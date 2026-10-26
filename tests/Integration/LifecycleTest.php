<?php

/**
 * LifecycleTest
 *
 * Deactivating OIO leaves nothing running, and
 * activating it again puts its rules back.
 *
 * @package     OIO
 * @author      Clément Gayot
 * @repo        https://github.com/Striffly/octopus-image-optimizer
 *
 */

namespace Octopus\Tests\Integration;

use Octopus\Admin\Delivery;
use Octopus\Lifecycle;
use Octopus\Package\Job;
use Octopus\Package\Lock;
use Octopus\Tests\TestCase;

class LifecycleTest extends TestCase
{
	public function test_deactivating_octopus_stops_everything_it_runs()
	{
		global $wpdb;
		$job = $this->running_job();
		// As another request locking an image, see Lock.
		$wpdb->insert($wpdb->options, ['option_name' => Lock::PREFIX . 7, 'option_value' => time(), 'autoload' => 'no']);
		self::set(Delivery::OPTION, true);

		Lifecycle::deactivate();

		$this->assertFalse($job->is_active());
		$this->assertSame([], $job->get_batches());
		$this->assertFalse(wp_next_scheduled($job->get_identifier() . '_cron'));
		$this->assertFalse($job->is_processing());
		$this->assertSame('cancelled', $job->progress()['state']);
		$this->assertSame('0', $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name = %s", Lock::PREFIX . 7)));
		$this->assertFalse(Delivery::rules_written());
		$this->assertTrue((bool)get_option('_' . Delivery::OPTION), 'The settings stay.');
	}

	public function test_a_job_that_ended_stays_as_it_ended()
	{
		update_option(Job::PROGRESS, ['action' => Job::OPTIMISE, 'total' => 1, 'done' => 1, 'finished' => 1000, 'cancelled' => false]);

		Lifecycle::deactivate();

		$this->assertFalse(get_option(Job::PROGRESS)['cancelled']);
	}

	public function test_activating_octopus_writes_its_rules_again()
	{
		self::set(Delivery::OPTION, true);
		Lifecycle::deactivate();

		Lifecycle::activate();

		$this->assertTrue(Delivery::rules_written());
	}

	public function test_deactivating_octopus_on_a_network_stops_it_on_every_site()
	{
		$this->skipWithoutMultisite();
		$site = self::factory()->blog->create();
		switch_to_blog($site);
		$job = $this->running_job(new Job());
		restore_current_blog();

		Lifecycle::deactivate(true);

		switch_to_blog($site);
		$this->assertFalse($job->is_active());
		$this->assertFalse(wp_next_scheduled($job->get_identifier() . '_cron'));
		restore_current_blog();
	}

	/**
	 * Starts a job with a queue, a cron event and a
	 * lock, without its request to the site.
	 *
	 * @param Job|null $job
	 * @return Job
	 */
	private function running_job($job = null)
	{
		$job = $job ?: Job::instance();
		add_filter($job->get_identifier() . '_pre_dispatch', '__return_true');
		$this->assertTrue($job->start(Job::OPTIMISE, [1, 2, 3]));
		wp_schedule_event(time(), 'hourly', $job->get_identifier() . '_cron');
		$job->lock_process();
		$this->assertTrue($job->is_active());
		return $job;
	}
}
