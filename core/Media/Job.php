<?php

/**
 * Job
 *
 * Job processes images of the media library in the
 * background (WP Background Processing): one item
 * per image, in batches within the PHP limits, and
 * keeps its progress for the options page.
 *
 * @package     OIO
 * @author      Clément Gayot
 * @category    Class
 * @repo        https://github.com/Striffly/octopus-image-optimizer
 *
 */

namespace Octopus\Media;

use Octopus_Vendor_WP_Background_Process;

if (!defined('ABSPATH')) {
	exit; // Exit if accessed directly
}

class Job extends Octopus_Vendor_WP_Background_Process
{
	/**
	 * Optimises the images.
	 */
	const OPTIMISE = 'optimise';

	/**
	 * Removes the WebP and AVIF files of the images.
	 */
	const REMOVE_FORMATS = 'remove_formats';

	/**
	 * Restores the original images.
	 */
	const RESTORE = 'restore';

	/**
	 * Option keeping the progress of the last job.
	 *
	 * @var string
	 */
	const PROGRESS = 'octopus_job';

	/**
	 * @var string
	 */
	protected $prefix = 'octopus';

	/**
	 * @var string
	 */
	protected $action = 'job';

	/**
	 * The instance, created on every request so that it
	 * handles its background requests and cron.
	 *
	 * @var Job|null
	 */
	private static $instance = null;

	/**
	 * Sets the prefix of the job: the library keeps
	 * its queue, status and lock in the site options,
	 * shared by the sites of a network. Each site has
	 * its own (octopus_2_job), or a job started on a site
	 * would be run on another, with its image IDs.
	 *
	 * @since 1.0.0
	 */
	public function __construct()
	{
		if (is_multisite()) {
			$this->prefix = 'octopus_' . get_current_blog_id();
		}
		parent::__construct();
	}

	/**
	 * Returns the instance.
	 *
	 * @return Job
	 * @since 1.0.0
	 */
	public static function instance()
	{
		if (self::$instance === null) {
			self::$instance = new self();
			add_action(self::$instance->identifier . '_cancelled', [self::$instance, 'cancelled_progress']);
		}
		return self::$instance;
	}

	/**
	 * Starts a job on images, unless one is running.
	 *
	 * @param string $action
	 * @param int[] $ids
	 * @param string $mode
	 * @return bool
	 * @since 1.0.0
	 */
	public function start($action, $ids, $mode = Optimiser::MODE_NEW)
	{
		if ($this->is_active() || empty($ids)) {
			return false;
		}
		update_option(self::PROGRESS, [
			'action' => $action,
			'mode' => $mode,
			'total' => count($ids),
			'done' => 0,
			'failed' => 0,
			'missing' => 0,
			'started' => time(),
			'finished' => null,
			'cancelled' => false,
		], false);
		// The library saves the whole batch after each item: keep batches small.
		foreach (array_chunk($ids, 100) as $chunk) {
			foreach ($chunk as $id) {
				$this->push_to_queue((int)$id);
			}
			$this->save();
		}
		$this->dispatch();
		return true;
	}

	/**
	 * Returns the progress of the last job, with its
	 * state: running, paused, finished or cancelled.
	 * Null if no job ever ran.
	 *
	 * @return array|null
	 * @since 1.0.0
	 */
	public function progress()
	{
		$progress = get_option(self::PROGRESS, null);
		if (!is_array($progress)) {
			return null;
		}
		if ($progress['cancelled']) {
			$progress['state'] = 'cancelled';
		} elseif ($progress['finished']) {
			$progress['state'] = 'finished';
		} elseif ($this->is_paused()) {
			$progress['state'] = 'paused';
		} else {
			$progress['state'] = 'running';
		}
		return $progress;
	}

	/**
	 * Processes one image.
	 *
	 * @param int $id
	 * @return false Removes the item from the queue.
	 * @since 1.0.0
	 */
	protected function task($id)
	{
		$progress = get_option(self::PROGRESS);
		if (!is_array($progress)) {
			return false;
		}
		Optimiser::$context = 'background';
		if (get_post($id)) {
			if ($progress['action'] === self::OPTIMISE) {
				Optimiser::optimise($id, $progress['mode']);
				if (Status::of($id) === Status::ERROR) {
					$progress['failed']++;
				}
			} elseif ($progress['action'] === self::REMOVE_FORMATS) {
				Cleaner::remove_formats($id);
			} elseif ($progress['action'] === self::RESTORE && Cleaner::restore($id) === false) {
				$progress['missing']++;
			}
		}
		$progress['done']++;
		update_option(self::PROGRESS, $progress, false);
		return false;
	}

	/**
	 * Marks the job as finished.
	 *
	 * @since 1.0.0
	 */
	protected function complete()
	{
		parent::complete();
		$progress = get_option(self::PROGRESS);
		if (is_array($progress) && !$progress['cancelled']) {
			$progress['finished'] = time();
			update_option(self::PROGRESS, $progress, false);
		}
	}

	/**
	 * Stops the job for good, without the request the
	 * library sends to cancel it: its queue, status,
	 * lock and cron event go, and a job in progress is
	 * marked as cancelled. When OIO is deactivated.
	 *
	 * @since 1.0.0
	 */
	public function stop()
	{
		$this->delete_all();
		$this->clear_scheduled_event();
		$this->unlock_process();
		$this->cancelled_progress();
	}

	/**
	 * Marks the job in progress as cancelled, once the
	 * library has emptied its queue. A job that ended
	 * stays as it ended.
	 *
	 * @since 1.0.0
	 */
	public function cancelled_progress()
	{
		$progress = get_option(self::PROGRESS);
		if (is_array($progress) && empty($progress['finished'])) {
			$progress['cancelled'] = true;
			$progress['finished'] = time();
			update_option(self::PROGRESS, $progress, false);
		}
	}
}
