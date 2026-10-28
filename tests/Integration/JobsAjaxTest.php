<?php

/**
 * JobsAjaxTest
 *
 * The jobs started from the options page (JobsAjax).
 *
 * @package     OIO
 * @author      Clément Gayot
 * @category    Tests
 * @repo        https://github.com/Striffly/octopus-image-optimizer
 *
 */

namespace Octopus\Tests\Integration;

use Octopus\Admin\Ajax;
use Octopus\Admin\JobsAjax;
use Octopus\Media\Job;
use Octopus\Tests\TestCase;
use ReflectionProperty;
use WPAjaxDieContinueException;
use WPAjaxDieStopException;

class JobsAjaxTest extends TestCase
{
	public function tear_down()
	{
		Job::instance()->delete_all();
		unset($_REQUEST['_ajax_nonce'], $_REQUEST['action'], $_POST['job'], $_POST['light']);
		parent::tear_down();
	}

	public function test_a_job_starts_from_the_options_page()
	{
		$id = $this->upload_unoptimised('photo.jpg');
		$job = Job::instance();
		$property = new ReflectionProperty($job, 'identifier');
		$property->setAccessible(true);
		// Without its request to the site, sent once the chain of the job is known.
		add_filter($property->getValue($job) . '_pre_dispatch', '__return_true');

		$response = $this->request('octopus_job_start', ['job' => 'new']);

		$this->assertTrue($response['success'], 'The library checked its own nonce in this request.');
		$this->assertSame([$id], $job->get_batches()[0]->data);
	}

	public function test_the_progress_is_asked_alone_while_a_job_runs()
	{
		$this->upload_unoptimised('photo.jpg');

		$light = $this->request('octopus_job_status', ['light' => '1']);
		$full = $this->request('octopus_job_status', ['light' => '']);

		$this->assertSame(['job'], array_keys($light['data']), 'Without the counts, which take longer on a big media library.');
		$this->assertSame(['job', 'counts', 'savings'], array_keys($full['data']));
	}

	/**
	 * Sends a request of the options page, by an
	 * administrator.
	 *
	 * @param string $action
	 * @param array $data
	 * @return array The JSON response.
	 */
	private function request($action, $data)
	{
		wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
		if (is_multisite()) {
			grant_super_admin(get_current_user_id());
		}
		$_REQUEST['_ajax_nonce'] = wp_create_nonce(Ajax::NONCE);
		$_REQUEST['action'] = $action;
		$_POST = array_merge($_POST, $data);
		add_filter('wp_doing_ajax', '__return_true');
		add_filter('wp_die_ajax_handler', function () {
			return function ($message) {
				if ($message === -1 || $message === '-1') {
					throw new WPAjaxDieStopException('-1');
				}
				throw new WPAjaxDieContinueException('');
			};
		});
		ob_start();
		try {
			// octopus_job_start runs start(), octopus_job_status status().
			$method = substr($action, strlen('octopus_job_'));
			(new JobsAjax())->$method();
		} catch (WPAjaxDieContinueException $e) {
			// wp_send_json() ends the request.
		} finally {
			$output = ob_get_clean();
		}
		return json_decode($output, true);
	}
}
