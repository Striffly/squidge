<?php

/**
 * Job
 *
 * Job processes images of the media library in the
 * background (WP Background Processing): one item
 * per image, in batches within the PHP limits, and
 * keeps its progress for the options page.
 *
 * @package     Squidge
 * @version     0.1.4
 * @author      Ainsley Clark
 * @category    Class
 * @repo        https://github.com/ainsleyclark/squidge
 *
 */

namespace Squidge\Package;

use Squidge_Vendor_WP_Background_Process;

if (!defined('ABSPATH')) {
	exit; // Exit if accessed directly
}

class Job extends Squidge_Vendor_WP_Background_Process
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
	 * Modes of the optimisation: the missing formats
	 * only, the images with older settings brought up
	 * to date, or everything again from the originals
	 * (thumbnails included).
	 */
	const MODE_NEW = 'new';
	const MODE_OUTDATED = 'outdated';
	const MODE_ALL = 'all';

	/**
	 * Option keeping the progress of the last job.
	 *
	 * @var string
	 */
	const PROGRESS = 'squidge_job';

	/**
	 * @var string
	 */
	protected $prefix = 'squidge';

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
	 * Returns the instance.
	 *
	 * @return Job
	 * @since 0.1.5
	 * @date 25/10/2026
	 */
	public static function instance()
	{
		if (self::$instance === null) {
			self::$instance = new self();
			add_action('squidge_job_cancelled', [self::$instance, 'cancelled_progress']);
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
	 * @since 0.1.5
	 * @date 25/10/2026
	 */
	public function start($action, $ids, $mode = self::MODE_NEW)
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
	 * @since 0.1.5
	 * @date 25/10/2026
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
	 * @since 0.1.5
	 * @date 25/10/2026
	 */
	protected function task($id)
	{
		$progress = get_option(self::PROGRESS);
		if (!is_array($progress)) {
			return false;
		}
		if (get_post($id)) {
			if ($progress['action'] === self::OPTIMISE) {
				if ($progress['mode'] === self::MODE_NEW) {
					Optimiser::attachment($id, null, Optimiser::settings());
				} else {
					Optimiser::update($id, $progress['mode'] === self::MODE_ALL);
				}
				if (Status::of($id) === Status::ERROR) {
					$progress['failed']++;
				}
			} elseif ($progress['action'] === self::REMOVE_FORMATS) {
				Cleaner::remove_formats($id);
			} elseif ($progress['action'] === self::RESTORE && !Cleaner::restore($id)) {
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
	 * @since 0.1.5
	 * @date 25/10/2026
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
	 * Marks the job as cancelled, once the library has
	 * emptied its queue.
	 *
	 * @since 0.1.5
	 * @date 25/10/2026
	 */
	public function cancelled_progress()
	{
		$progress = get_option(self::PROGRESS);
		if (is_array($progress)) {
			$progress['cancelled'] = true;
			$progress['finished'] = time();
			update_option(self::PROGRESS, $progress, false);
		}
	}
}
