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

if (!defined('ABSPATH')) {
	exit; // Exit if accessed directly
}

class Job extends Background
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
		// A pause or a cancel left once the queue emptied would keep the job active, with nothing to resume.
		// A worker still ending its image stops on its own (see Background::should_continue()).
		if ($this->is_queued() || empty($ids)) {
			return false;
		}
		delete_site_option($this->get_status_key());
		update_option(self::PROGRESS, [
			// Tells this job from the next one, see task().
			'key' => wp_generate_password(12, false),
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
	 * Pauses the job, while images are left to process:
	 * a pause sent as it ends, e.g. clicked on its last
	 * image, does nothing.
	 *
	 * @since 1.0.0
	 */
	public function pause()
	{
		if ($this->is_queued()) {
			parent::pause();
		}
	}

	/**
	 * Cancels the job, while images are left to process,
	 * as pause() does: at once, in this request, without
	 * the one the library sends to the site, nor waiting
	 * for a worker that PHP may have stopped. The queue
	 * goes; a worker at work ends its image, then stops
	 * without saving its batch back (see Background),
	 * and the next job starts without waiting for it.
	 *
	 * @since 1.0.0
	 */
	public function cancel()
	{
		if (!$this->is_queued()) {
			return;
		}
		foreach ($this->get_batches() as $batch) {
			$this->delete($batch->key);
		}
		// A pause left goes too: nothing is left to resume.
		delete_site_option($this->get_status_key());
		$this->clear_scheduled_event();
		$this->cancelled_progress();
		$this->free_worker();
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
	 * @return int|false Removes the item from the queue, or keeps it while another request processes the image.
	 * @since 1.0.0
	 */
	protected function task($id)
	{
		$progress = self::stored();
		if (!is_array($progress) || $progress['cancelled']) {
			return false;
		}
		Optimiser::$context = 'background';
		$counts = ['done' => 1, 'failed' => 0, 'missing' => 0];
		if (get_post($id)) {
			if ($progress['action'] === self::OPTIMISE) {
				if (Optimiser::optimise($id, $progress['mode']) === null) {
					// Another request processes it, e.g. a worker PHP stopped, its lock not stale yet: again after the others.
					sleep(1);
					return $id;
				}
				$counts['failed'] = (int)(Status::of($id) === Status::ERROR);
			} elseif ($progress['action'] === self::REMOVE_FORMATS) {
				Cleaner::remove_formats($id);
			} elseif ($progress['action'] === self::RESTORE) {
				$counts['missing'] = (int)(Cleaner::restore($id) === false);
			}
		}
		// Read again: cancelled meanwhile, it stays so, and another job keeps its counts.
		$stored = self::stored();
		if (is_array($stored) && self::identity($stored) === self::identity($progress)) {
			foreach ($counts as $name => $count) {
				$stored[$name] += $count;
			}
			update_option(self::PROGRESS, $stored, false);
		}
		return false;
	}

	/**
	 * Returns what tells a job from another: its key,
	 * or its start for a job started without one.
	 *
	 * @param array $progress
	 * @return string
	 * @since 1.0.0
	 */
	private static function identity($progress)
	{
		return isset($progress['key']) ? $progress['key'] : (string)$progress['started'];
	}

	/**
	 * Returns the progress saved, read from the
	 * database: written by other requests (a cancel),
	 * which the cache of a worker would not know.
	 *
	 * @return array|false
	 * @since 1.0.0
	 */
	private static function stored()
	{
		wp_cache_delete(self::PROGRESS, 'options');
		return get_option(self::PROGRESS);
	}

	/**
	 * Marks the job as finished.
	 *
	 * @since 1.0.0
	 */
	protected function complete()
	{
		parent::complete();
		$progress = self::stored();
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
		parent::stop();
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
		$progress = self::stored();
		if (is_array($progress) && empty($progress['finished'])) {
			$progress['cancelled'] = true;
			$progress['finished'] = time();
			update_option(self::PROGRESS, $progress, false);
		}
	}
}
