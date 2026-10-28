<?php

/**
 * JobTest
 *
 * The background jobs of the media library, run
 * in the test request instead of a request of
 * their own.
 *
 * @package     OIO
 * @author      Clément Gayot
 * @repo        https://github.com/Striffly/octopus-image-optimizer
 *
 */

namespace Octopus\Tests\Integration;

use Octopus\Encoders\WebP;
use Octopus\Media\Files;
use Octopus\Media\Job;
use Octopus\Media\Lock;
use Octopus\Media\Optimiser;
use Octopus\Media\Status;
use Octopus\Tests\TestCase;
use ReflectionMethod;
use ReflectionProperty;

class JobTest extends TestCase
{
	/**
	 * The job, without its request to the site: the
	 * test runs it.
	 *
	 * @var Job
	 */
	private $job;

	public function set_up()
	{
		parent::set_up();
		$this->require_libraries('cwebp', 'jpegoptim');
		$this->job = Job::instance();
		$identifier = self::identifier($this->job);
		add_filter($identifier . '_pre_dispatch', '__return_true');
		add_filter($identifier . '_wp_die', '__return_false');
	}

	public function test_the_new_images_are_optimised_in_background()
	{
		$ids = [$this->upload_unoptimised('photo.jpg'), $this->upload_unoptimised('graphic.png')];

		$this->assertTrue($this->job->start(Job::OPTIMISE, $ids));
		$this->assertSame('running', $this->job->progress()['state']);
		$this->assertFalse($this->job->start(Job::OPTIMISE, $ids), 'One job at a time.');
		$contexts = [];
		add_filter('octopus_threads', function ($threads, $context) use (&$contexts) {
			$contexts[] = $context;
			return $threads;
		}, 10, 2);
		$this->run_job();

		$progress = $this->job->progress();
		$this->assertSame('finished', $progress['state']);
		$this->assertSame([2, 0], [$progress['done'], $progress['failed']]);
		foreach ($ids as $id) {
			$this->assertSame(Status::OPTIMISED, Status::of($id));
		}
		$this->assertFalse($this->job->is_active());
		$this->assertSame([Optimiser::BACKGROUND], array_values(array_unique($contexts)));
	}

	public function test_the_images_in_error_are_counted()
	{
		$ids = [$this->upload_unoptimised('photo.jpg'), $this->upload_unoptimised('damaged.jpg')];

		$this->job->start(Job::OPTIMISE, $ids);
		$this->run_job();

		$this->assertSame([2, 1], [$this->job->progress()['done'], $this->job->progress()['failed']]);
	}

	public function test_the_outdated_images_are_optimised_again()
	{
		$id = $this->upload('photo.jpg');
		self::set('webp_quality', 40);

		$this->job->start(Job::OPTIMISE, [$id], Optimiser::MODE_OUTDATED);
		$this->run_job();

		$this->assertSame('quality:40', get_post_meta($id, WebP::meta_key(), true));
		$this->assertSame(Status::OPTIMISED, Status::of($id));
	}

	public function test_the_restore_counts_the_images_without_original()
	{
		$kept = $this->upload('photo.jpg');
		$lost = $this->upload('photo.jpg');
		unlink(Files::backup_path(get_attached_file($lost)));

		$this->job->start(Job::RESTORE, [$kept, $lost]);
		$this->run_job();

		$progress = $this->job->progress();
		$this->assertSame([2, 1], [$progress['done'], $progress['missing']]);
		$this->assertFileEquals(self::fixture('photo.jpg'), get_attached_file($kept));
	}

	public function test_a_pause_sent_once_the_job_ended_does_nothing()
	{
		$this->job->start(Job::OPTIMISE, [$this->upload_unoptimised('photo.jpg')]);
		$this->run_job();

		$this->job->pause();
		$this->job->cancel();

		$this->assertFalse($this->job->is_active());
		$this->assertSame('finished', $this->job->progress()['state']);
	}

	public function test_a_pause_left_with_an_empty_queue_does_not_block_the_next_job()
	{
		update_site_option(self::identifier($this->job) . '_status', Job::STATUS_PAUSED);

		$this->assertTrue($this->job->start(Job::REMOVE_FORMATS, [1, 2]), 'Nothing to resume: the job starts.');
		$this->assertFalse($this->job->is_paused());
		$this->job->delete_all();
	}

	public function test_the_queue_is_kept_under_the_identifier_of_the_site()
	{
		$identifier = is_multisite() ? 'octopus_' . get_current_blog_id() . '_job' : 'octopus_job';
		$this->assertSame($identifier, self::identifier($this->job));

		$this->job->start(Job::REMOVE_FORMATS, [1, 2]);

		$batches = $this->job->get_batches();
		$this->assertCount(1, $batches);
		$this->assertStringStartsWith($identifier . '_batch_', $batches[0]->key);
		$this->assertSame([1, 2], $batches[0]->data);
		$this->job->delete_all();
	}

	public function test_the_cron_goes_on_while_a_job_runs()
	{
		$this->job->start(Job::REMOVE_FORMATS, [1, 2]);
		$this->other_worker();
		$ran = false;
		add_action('octopus_test_next_event', function () use (&$ran) {
			$ran = true;
		});

		// As wp-cron.php runs the events due, one after the other.
		$this->job->handle_cron_healthcheck();
		do_action('octopus_test_next_event');

		$this->assertTrue($ran, 'The library ended the request: the next events waited.');
		$this->job->delete_all();
	}

	public function test_a_queue_written_with_objects_creates_none()
	{
		// E.g. written in the database through a flaw of another plugin.
		update_site_option(self::identifier($this->job) . '_batch_injected', [new \ArrayObject([1])]);

		$batches = $this->job->get_batches();

		$this->assertInstanceOf(\__PHP_Incomplete_Class::class, $batches[0]->data[0]);
		$this->job->delete_all();
	}

	public function test_each_site_of_a_network_has_its_own_job()
	{
		$this->skipWithoutMultisite();
		$site = self::factory()->blog->create();
		switch_to_blog($site);
		$job = new Job();
		restore_current_blog();

		$this->assertSame('octopus_' . $site . '_job', self::identifier($job));
	}

	public function test_the_worker_holds_its_lock_in_the_database_while_it_runs()
	{
		$this->job->start(Job::OPTIMISE, [$this->upload_unoptimised('photo.jpg')]);
		$seen = [];
		add_action('octopus_working', function () use (&$seen) {
			$seen[] = $this->job->is_processing();
		});

		$this->run_job();

		$this->assertNotEmpty($seen);
		$this->assertNotContains(false, $seen, 'Held while the libraries run.');
		$this->assertFalse($this->job->is_processing(), 'Released at the end.');
		$this->assertFalse(get_site_transient(self::identifier($this->job) . '_process_lock'), 'Not a transient, which a persistent object cache may drop.');
	}

	public function test_a_worker_never_runs_alongside_another()
	{
		$this->job->start(Job::OPTIMISE, [$this->upload_unoptimised('photo.jpg')]);
		$this->other_worker();

		$this->handle();

		$this->assertSame(0, $this->job->progress()['done']);
		$this->assertCount(1, $this->job->get_batches());
		$this->job->delete_all();
	}

	public function test_a_cancel_is_applied_at_once_without_a_worker()
	{
		$this->job->start(Job::REMOVE_FORMATS, [1, 2]);
		// Its worker stopped with PHP: its lock went stale.
		$this->other_worker(120);

		$this->job->cancel();

		$this->assertSame('cancelled', $this->job->progress()['state']);
		$this->assertSame([], $this->job->get_batches());
		$this->assertFalse($this->job->is_active());
	}

	public function test_a_cancel_is_applied_at_once_with_a_worker_at_work()
	{
		$this->job->start(Job::REMOVE_FORMATS, [1, 2]);
		$this->other_worker();

		$this->job->cancel();

		$this->assertSame('cancelled', $this->job->progress()['state']);
		$this->assertSame([], $this->job->get_batches());
		$this->assertFalse($this->job->is_processing(), 'The next job does not wait for the worker.');
		$this->assertTrue($this->job->start(Job::REMOVE_FORMATS, [3]));
		$this->job->delete_all();
	}

	public function test_a_worker_ends_its_image_then_stops_once_the_job_is_cancelled()
	{
		$ids = [$this->upload_unoptimised('photo.jpg'), $this->upload_unoptimised('photo.jpg'), $this->upload_unoptimised('photo.jpg')];
		$this->job->start(Job::OPTIMISE, $ids);
		// Cancelled by another request while the worker encodes the first image.
		$cancelled = false;
		$told = null;
		add_action('octopus_working', function () use (&$cancelled, &$told) {
			if (!$cancelled) {
				$cancelled = true;
				(new Job())->cancel();
				$told = (new Job())->progress()['state'];
			}
		});

		$this->handle();

		$this->assertSame('cancelling', $told, 'Its image in progress, the page waits for its count.');
		$progress = $this->job->progress();
		$this->assertSame('cancelled', $progress['state'], 'Not finished: the worker kept its own copy of the progress.');
		$this->assertSame(1, $progress['done'], 'Its image is ended, and counted.');
		$this->assertSame([], $this->job->get_batches(), 'Its batch is not saved back.');
		$this->assertSame(Status::OPTIMISED, Status::of($ids[0]));
		$this->assertSame([Status::TO_OPTIMISE, Status::TO_OPTIMISE], [Status::of($ids[1]), Status::of($ids[2])]);
	}

	public function test_a_cancel_waits_for_no_image_of_a_worker_php_stopped()
	{
		$id = $this->upload_unoptimised('photo.jpg');
		$this->job->start(Job::OPTIMISE, [$id, $this->upload_unoptimised('photo.jpg')]);
		// Its worker stopped on the first image: the lock of the image, no longer refreshed, is old.
		update_option(Job::PROGRESS, array_merge(get_option(Job::PROGRESS), ['current' => $id]), false);
		$lock = Lock::take(Lock::PREFIX . $id, Lock::TIMEOUT);
		global $wpdb;
		$wpdb->update($wpdb->options, ['option_value' => microtime(true) - 3 * Lock::BEAT], ['option_name' => Lock::PREFIX . $id, 'option_value' => $lock]);

		$this->job->cancel();

		$this->assertSame('cancelled', $this->job->progress()['state']);
	}

	public function test_a_job_started_right_after_a_cancel_never_gets_the_images_cancelled()
	{
		$ids = [$this->upload_unoptimised('photo.jpg'), $this->upload_unoptimised('photo.jpg'), $this->upload_unoptimised('photo.jpg')];
		$next = $this->upload_unoptimised('graphic.png');
		$this->job->start(Job::OPTIMISE, $ids);
		// Cancelled, then another job started, while the worker encodes the first image.
		$cancelled = false;
		add_action('octopus_working', function () use (&$cancelled, $next) {
			if (!$cancelled) {
				$cancelled = true;
				(new Job())->cancel();
				(new Job())->start(Job::OPTIMISE, [$next]);
			}
		});

		$this->handle();
		$this->run_job();

		$this->assertSame([Status::TO_OPTIMISE, Status::TO_OPTIMISE], [Status::of($ids[1]), Status::of($ids[2])], 'The worker did not put the images cancelled back.');
		$this->assertSame(Status::OPTIMISED, Status::of($next));
		$progress = $this->job->progress();
		$this->assertSame(['finished', 1, 1], [$progress['state'], $progress['total'], $progress['done']], 'The image ended by the worker counts for the job cancelled.');
	}

	public function test_the_stale_locks_of_the_images_go_once_a_job_ends()
	{
		global $wpdb;
		// Left by a worker PHP stopped, on an image the job never processes again.
		$wpdb->insert($wpdb->options, ['option_name' => Lock::PREFIX . 999, 'option_value' => time() - Lock::TIMEOUT - 1, 'autoload' => 'no']);
		$this->job->start(Job::OPTIMISE, [$this->upload_unoptimised('photo.jpg')]);

		$this->run_job();

		$this->assertSame('0', $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name = %s", Lock::PREFIX . 999)));
	}

	public function test_an_image_another_request_processes_waits_instead_of_being_skipped()
	{
		$id = $this->upload_unoptimised('photo.jpg');
		$this->job->start(Job::OPTIMISE, [$id]);
		// E.g. the worker PHP stopped on it, its lock not stale yet.
		$lock = Lock::take(Lock::PREFIX . $id, Lock::TIMEOUT);
		add_filter(self::identifier($this->job) . '_default_time_limit', function () {
			return 1;
		});

		$this->handle();

		$progress = $this->job->progress();
		$this->assertSame(0, $progress['done']);
		$this->assertSame([$id], $this->job->get_batches()[0]->data, 'Kept in the queue.');
		$this->assertGreaterThan(Lock::TIMEOUT - 10, $progress['waiting'], 'The page tells how long it may still wait.');
		$this->assertLessThanOrEqual(Lock::TIMEOUT, $progress['waiting']);

		Lock::free(Lock::PREFIX . $id, $lock);
		$told = [];
		add_action('octopus_working', function () use (&$told) {
			$told[] = $this->job->progress()['waiting'];
		});
		$this->run_job();
		$progress = $this->job->progress();
		$this->assertSame([1, 0], [$progress['done'], $progress['waiting']]);
		$this->assertSame([0], array_values(array_unique($told)), 'Not told while the worker processes it.');
		$this->assertSame(Status::OPTIMISED, Status::of($id));
	}

	public function test_a_job_its_worker_left_is_started_again()
	{
		$this->job->start(Job::REMOVE_FORMATS, [1, 2]);
		$dispatched = 0;
		add_filter(self::identifier($this->job) . '_pre_dispatch', function ($cancel) use (&$dispatched) {
			$dispatched++;
			return $cancel;
		}, 20);

		$this->other_worker();
		$this->job->settle();
		$this->assertSame(0, $dispatched, 'Its worker runs.');

		$this->other_worker(120);
		$this->job->settle();
		$this->assertSame(1, $dispatched);

		$this->job->pause();
		$this->job->settle();
		$this->assertSame(1, $dispatched, 'Paused, it waits.');
		$this->job->delete_all();
	}

	/**
	 * Runs the job until its queue is empty, as its
	 * requests to the site would.
	 */
	private function run_job()
	{
		$this->handle();
		$this->assertSame([], $this->job->get_batches());
	}

	/**
	 * Runs the request of the job processing its queue.
	 */
	private function handle()
	{
		$handle = new ReflectionMethod($this->job, 'handle');
		$handle->setAccessible(true);
		$handle->invoke($this->job);
	}

	/**
	 * Locks the job as another request working on it
	 * would, its lock refreshed some seconds ago: stale
	 * past the lock time of the library (60 s).
	 *
	 * @param int $age
	 */
	private function other_worker($age = 0)
	{
		global $wpdb;
		$wpdb->replace($wpdb->options, ['option_name' => Lock::PREFIX . 'worker_job', 'option_value' => sprintf('%.6F', microtime(true) - $age), 'autoload' => 'no']);
	}

	/**
	 * Returns the identifier of a job, the prefix of
	 * its options and hooks.
	 *
	 * @param Job $job
	 * @return string
	 */
	private static function identifier($job)
	{
		$property = new ReflectionProperty($job, 'identifier');
		$property->setAccessible(true);
		return $property->getValue($job);
	}
}
