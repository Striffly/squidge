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
		$this->run_job();

		$progress = $this->job->progress();
		$this->assertSame('finished', $progress['state']);
		$this->assertSame([2, 0], [$progress['done'], $progress['failed']]);
		foreach ($ids as $id) {
			$this->assertSame(Status::OPTIMISED, Status::of($id));
		}
		$this->assertFalse($this->job->is_active());
		$this->assertSame('background', Optimiser::$context);
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
		set_site_transient(self::identifier($this->job) . '_process_lock', microtime(), 60);
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

	/**
	 * Runs the job until its queue is empty, as its
	 * requests to the site would.
	 */
	private function run_job()
	{
		$handle = new ReflectionMethod($this->job, 'handle');
		$handle->setAccessible(true);
		$handle->invoke($this->job);
		$this->assertSame([], $this->job->get_batches());
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
