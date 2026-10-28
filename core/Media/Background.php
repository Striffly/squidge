<?php

/**
 * Background
 *
 * Background is what the queues of images OIO runs in
 * the background share (WP Background Processing): the
 * jobs of the options page (Job) and the images of the
 * media library (Queue).
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

abstract class Background extends Octopus_Vendor_WP_Background_Process
{
	/**
	 * @var string
	 */
	protected $prefix = 'octopus';

	/**
	 * The batch the worker processes, see get_batch().
	 *
	 * @var string|null
	 */
	private $batch = null;

	/**
	 * The value of the lock of the worker, while this
	 * request is the worker of the queue (see
	 * take_worker()).
	 *
	 * @var string|null
	 */
	private $worker = null;

	/**
	 * When this request last refreshed the lock of the
	 * worker.
	 *
	 * @var float
	 */
	private $beaten = 0.0;

	/**
	 * The instance of each queue, by class.
	 *
	 * @var Background[]
	 */
	private static $instances = [];

	/**
	 * Returns the instance of the queue, created on
	 * every request so that it handles its background
	 * requests and cron.
	 *
	 * @return static
	 * @since 1.0.0
	 */
	public static function instance()
	{
		if (!isset(self::$instances[static::class])) {
			self::$instances[static::class] = new static();
			self::$instances[static::class]->hooks();
		}
		return self::$instances[static::class];
	}

	/**
	 * Hooks the instance of the queue, once.
	 *
	 * @since 1.0.0
	 */
	protected function hooks()
	{
	}

	/**
	 * Sets the prefix of the queue: the library keeps
	 * it, with its status and lock, in the site
	 * options, shared by the sites of a network. Each
	 * site has its own (octopus_2_job), or a queue
	 * filled on a site would be run on another, with
	 * its image IDs.
	 *
	 * @since 1.0.0
	 */
	public function __construct()
	{
		if (is_multisite()) {
			$this->prefix = 'octopus_' . get_current_blog_id();
		}
		// The queue holds image IDs only: read back without any object, so that
		// a queue written by someone else in the database creates none.
		parent::__construct(false);
	}

	/**
	 * The request that runs the queue in the
	 * background: as WordPress spawns its cron
	 * (spawn_cron()), it does not wait for an answer,
	 * which only comes once a batch is done. With the 5
	 * seconds of the library, starting a job kept the
	 * page waiting as long; the cron of the library
	 * starts a queue whose request was lost.
	 *
	 * @return array
	 * @since 1.0.0
	 */
	protected function get_post_args()
	{
		return array_merge(parent::get_post_args(), ['timeout' => 0.01]);
	}

	/**
	 * Empties the queue for good, without the request
	 * the library sends to cancel it: its batches,
	 * status, lock and cron event go. When OIO is
	 * deactivated.
	 *
	 * @since 1.0.0
	 */
	public function stop()
	{
		$this->delete_all();
		$this->clear_scheduled_event();
		$this->free_worker();
	}

	/**
	 * Ends the queue, emptied: the locks of the images
	 * gone stale go too (Lock::sweep()).
	 *
	 * @since 1.0.0
	 */
	protected function complete()
	{
		parent::complete();
		Lock::sweep();
	}

	/**
	 * Releases the lock of the worker, whoever holds it:
	 * a worker at work stops after its image, once it
	 * sees it lost its lock (see should_continue()), and
	 * the next queue does not wait for it.
	 *
	 * @since 1.0.0
	 */
	protected function free_worker()
	{
		Lock::free($this->worker_lock());
		$this->worker = null;
	}

	/**
	 * Starts again a queue no worker runs any more, e.g.
	 * stopped with PHP, as the cron of the library does
	 * 5 minutes later: once its lock is stale, while a
	 * page follows the queue. Not paused.
	 *
	 * @since 1.0.0
	 */
	public function settle()
	{
		if ($this->is_queued() && !$this->is_processing() && !$this->is_paused()) {
			$this->dispatch();
		}
	}

	/**
	 * Processes the queue, as its worker: never two at
	 * once. The library tells it with a transient, which
	 * a persistent object cache may drop before its time,
	 * and does not refresh it while an image takes
	 * longer: its cron would start a second worker. The
	 * lock of the worker is in the database (Lock),
	 * refreshed while the libraries run. The request
	 * that dispatched the worker is answered at once.
	 *
	 * @since 1.0.0
	 */
	protected function handle()
	{
		if (!$this->take_worker()) {
			return $this->maybe_wp_die();
		}
		// Emptied since it was dispatched, e.g. cancelled.
		if ($this->is_queue_empty()) {
			$this->unlock_process();
			return $this->maybe_wp_die();
		}
		// As wp-cron.php does: the request that dispatched it no longer waits for the batch, a second at
		// least as Requests rounds its timeout up, e.g. the click starting a job.
		if (function_exists('fastcgi_finish_request')) {
			fastcgi_finish_request();
		} elseif (function_exists('litespeed_finish_request')) {
			litespeed_finish_request();
		}
		add_action('octopus_working', [$this, 'beat']);
		try {
			return parent::handle();
		} finally {
			remove_action('octopus_working', [$this, 'beat']);
			$this->unlock_process();
			$this->batch = null;
		}
	}

	/**
	 * Keeps an image another request is processing in
	 * the queue, e.g. a worker PHP stopped, its lock not
	 * stale yet: tried again after the others, a second
	 * later at least.
	 *
	 * @param int $id
	 * @return int The item kept, see task().
	 * @since 1.0.0
	 */
	protected function retry($id)
	{
		sleep(1);
		return $id;
	}

	/**
	 * Determines if a worker processes the queue: its
	 * lock is there, refreshed within the lock time of
	 * the library, 60 seconds by default.
	 *
	 * @return bool
	 * @since 1.0.0
	 */
	public function is_processing()
	{
		return Lock::alive($this->worker_lock(), $this->worker_timeout());
	}

	/**
	 * Takes or refreshes the lock of the worker, as the
	 * library starts a batch.
	 *
	 * @param bool $reset_start_time
	 * @since 1.0.0
	 */
	public function lock_process($reset_start_time = true)
	{
		if ($reset_start_time) {
			$this->start_time = time();
		}
		if ($this->worker === null) {
			$this->take_worker();
		} else {
			$this->beat(true);
		}
	}

	/**
	 * Releases the lock of the worker, if this request
	 * still holds it.
	 *
	 * @return $this
	 * @since 1.0.0
	 */
	protected function unlock_process()
	{
		if ($this->worker !== null) {
			Lock::free($this->worker_lock(), $this->worker);
			$this->worker = null;
		}
		return $this;
	}

	/**
	 * Determines if the worker goes on with the next
	 * image: refreshes its lock, and stops once another
	 * request took it over, the lock stale, or emptied
	 * its batch, the job cancelled (see Job::cancel()).
	 *
	 * @return bool
	 * @since 1.0.0
	 */
	public function should_continue()
	{
		$this->beat();
		return $this->worker !== null && !$this->batch_gone() && parent::should_continue();
	}

	/**
	 * Returns the next batch of the queue, kept as the
	 * one the worker processes.
	 *
	 * @return object|array The batch, an empty array if none.
	 * @since 1.0.0
	 */
	protected function get_batch()
	{
		$batch = parent::get_batch();
		$this->batch = empty($batch) ? null : $batch->key;
		return $batch;
	}

	/**
	 * Saves what is left of a batch after an image,
	 * unless it was emptied meanwhile, the job
	 * cancelled: the worker would put it back.
	 *
	 * @param string $key
	 * @param array $data
	 * @return $this
	 * @since 1.0.0
	 */
	public function update($key, $data)
	{
		if ($key === $this->batch && $this->batch_gone()) {
			return $this;
		}
		return parent::update($key, $data);
	}

	/**
	 * Determines if the batch the worker processes is
	 * gone from the database: read there, as the status
	 * of the library is, another request emptied it.
	 *
	 * @return bool
	 * @since 1.0.0
	 */
	private function batch_gone()
	{
		global $wpdb;
		if ($this->batch === null) {
			return false;
		}
		// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Written by another request: never the cache of this one.
		if (is_multisite()) {
			$found = $wpdb->get_var($wpdb->prepare("SELECT meta_id FROM {$wpdb->sitemeta} WHERE meta_key = %s AND site_id = %d LIMIT 1", $this->batch, get_current_network_id()));
		} else {
			$found = $wpdb->get_var($wpdb->prepare("SELECT option_id FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", $this->batch));
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery
		return $found === null;
	}

	/**
	 * Refreshes the lock of the worker, every BEAT
	 * seconds at most (Lock::BEAT), while this request
	 * holds it.
	 *
	 * @param bool $force Refreshed now.
	 * @since 1.0.0
	 */
	public function beat($force = false)
	{
		if ($this->worker === null || (!$force && microtime(true) - $this->beaten < Lock::BEAT)) {
			return;
		}
		$this->worker = Lock::refresh($this->worker_lock(), $this->worker);
		$this->beaten = microtime(true);
	}

	/**
	 * Takes the lock of the worker, unless another
	 * request holds it.
	 *
	 * @return bool
	 * @since 1.0.0
	 */
	private function take_worker()
	{
		if ($this->worker === null) {
			$this->worker = Lock::take($this->worker_lock(), $this->worker_timeout());
			$this->beaten = microtime(true);
		}
		return $this->worker !== null;
	}

	/**
	 * Returns the name of the lock of the worker, among
	 * the locks of the images (Lock::PREFIX), in the
	 * options of the site, as the queue is.
	 *
	 * @return string
	 * @since 1.0.0
	 */
	private function worker_lock()
	{
		return Lock::PREFIX . 'worker_' . $this->action;
	}

	/**
	 * Returns the seconds after which the lock of a
	 * worker that stopped refreshing it is stale: the
	 * lock time of the library, its filter included.
	 *
	 * @return int
	 * @since 1.0.0
	 */
	private function worker_timeout()
	{
		$default = property_exists($this, 'queue_lock_time') ? $this->queue_lock_time : 60;
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- The filter of the library, octopus_job_queue_lock_time.
		return max(Lock::BEAT * 2, (int)apply_filters($this->identifier . '_queue_lock_time', $default));
	}
}
