<?php

/**
 * Tools
 *
 * Tools starts and controls the background jobs from
 * the overview of the options page, and reports
 * their progress.
 *
 * @package     Squidge
 * @version     0.1.4
 * @author      Ainsley Clark
 * @category    Admin
 * @repo        https://github.com/ainsleyclark/squidge
 *
 */

namespace Squidge\Admin;

use Squidge\Package\Job;
use Squidge\Package\Status;

if (!defined('ABSPATH')) {
	exit; // Exit if accessed directly
}

class Tools
{
	/**
	 * Nonce action of the requests.
	 *
	 * @var string
	 */
	const NONCE = 'squidge_job';

	/**
	 * The jobs that can be started: the images, and if
	 * they are processed again even when done.
	 *
	 * @var array
	 */
	const JOBS = [
		'new' => ['action' => Job::OPTIMISE, 'statuses' => [Status::TO_OPTIMISE, Status::ERROR], 'force' => false],
		'outdated' => ['action' => Job::OPTIMISE, 'statuses' => [Status::OUTDATED], 'force' => true],
		'all' => ['action' => Job::OPTIMISE, 'statuses' => [null], 'force' => true],
	];

	/**
	 * Adds the AJAX requests.
	 *
	 * @since 0.1.5
	 * @date 25/10/2026
	 */
	public function __construct()
	{
		add_action('wp_ajax_squidge_job_start', [$this, 'start']);
		add_action('wp_ajax_squidge_job_status', [$this, 'status']);
		add_action('wp_ajax_squidge_job_control', [$this, 'control']);
	}

	/**
	 * Checks the nonce and the capability of a request.
	 *
	 * @since 0.1.5
	 * @date 25/10/2026
	 */
	private function authorize()
	{
		check_ajax_referer(self::NONCE);
		if (!current_user_can('manage_options')) {
			wp_send_json_error(null, 403);
		}
	}

	/**
	 * Starts a job on the images of its statuses.
	 *
	 * @since 0.1.5
	 * @date 25/10/2026
	 */
	public function start()
	{
		$this->authorize();
		$name = isset($_POST['job']) ? sanitize_key(wp_unslash($_POST['job'])) : '';
		if (!isset(self::JOBS[$name])) {
			wp_send_json_error(null, 400);
		}
		$job = self::JOBS[$name];
		$ids = [];
		foreach ($job['statuses'] as $status) {
			$ids = array_merge($ids, Status::ids($status));
		}
		if (!Job::instance()->start($job['action'], $ids, $job['force'])) {
			wp_send_json_error(['message' => 'A job is already running, or there is no image to process.'], 409);
		}
		$this->status();
	}

	/**
	 * Pauses, resumes or cancels the job.
	 *
	 * @since 0.1.5
	 * @date 25/10/2026
	 */
	public function control()
	{
		$this->authorize();
		$command = isset($_POST['command']) ? sanitize_key(wp_unslash($_POST['command'])) : '';
		$job = Job::instance();
		if ($command === 'pause') {
			$job->pause();
		} elseif ($command === 'resume') {
			$job->resume();
		} elseif ($command === 'cancel') {
			$job->cancel();
		} else {
			wp_send_json_error(null, 400);
		}
		$this->status();
	}

	/**
	 * Sends the progress of the job and the number of
	 * images by status.
	 *
	 * @since 0.1.5
	 * @date 25/10/2026
	 */
	public function status()
	{
		$this->authorize();
		wp_send_json_success([
			'job' => Job::instance()->progress(),
			'counts' => Status::counts(),
		]);
	}
}
