<?php

/**
 * Tools
 *
 * Tools starts and controls the background jobs from
 * the overview of the options page, and reports
 * their progress.
 *
 * @package     OIO
 * @author      Clément Gayot
 * @category    Admin
 * @repo        https://github.com/Striffly/octopus-image-optimizer
 *
 */

namespace OIO\Admin;

use OIO\Package\Cleaner;
use OIO\Package\Job;
use OIO\Package\Status;

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
	const NONCE = 'oio_job';

	/**
	 * The jobs that can be started: their action, their
	 * mode and the statuses of their images.
	 *
	 * @var array
	 */
	const JOBS = [
		'new' => ['action' => Job::OPTIMISE, 'mode' => Job::MODE_NEW, 'statuses' => [Status::TO_OPTIMISE, Status::ERROR]],
		'outdated' => ['action' => Job::OPTIMISE, 'mode' => Job::MODE_OUTDATED, 'statuses' => [Status::OUTDATED]],
		'all' => ['action' => Job::OPTIMISE, 'mode' => Job::MODE_ALL, 'statuses' => [null]],
		'remove_formats' => ['action' => Job::REMOVE_FORMATS, 'mode' => null, 'statuses' => []],
		'restore' => ['action' => Job::RESTORE, 'mode' => null, 'statuses' => []],
	];

	/**
	 * Adds the AJAX requests.
	 *
	 * @since 1.0.0
	 * @date 25/10/2026
	 */
	public function __construct()
	{
		add_action('wp_ajax_oio_job_start', [$this, 'start']);
		add_action('wp_ajax_oio_job_status', [$this, 'status']);
		add_action('wp_ajax_oio_job_control', [$this, 'control']);
	}

	/**
	 * Checks the nonce and the capability of a request.
	 *
	 * @since 1.0.0
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
	 * @since 1.0.0
	 * @date 25/10/2026
	 */
	public function start()
	{
		$this->authorize();
		$name = isset($_POST['job']) ? sanitize_key(wp_unslash($_POST['job'])) : '';
		if (!array_key_exists($name, self::JOBS)) {
			wp_send_json_error(null, 400);
		}
		$job = self::JOBS[$name];
		// The cleanup jobs process every image OIO processed.
		$ids = $job['statuses'] ? [] : Cleaner::ids();
		foreach ($job['statuses'] as $status) {
			$ids = array_merge($ids, Status::ids($status));
		}
		if (!Job::instance()->start($job['action'], $ids, $job['mode'])) {
			wp_send_json_error(['message' => 'A job is already running, or there is no image to process.'], 409);
		}
		$this->status();
	}

	/**
	 * Pauses, resumes or cancels the job.
	 *
	 * @since 1.0.0
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
	 * Sends the progress of the job, the number of
	 * images by status and the bytes saved.
	 *
	 * @since 1.0.0
	 * @date 25/10/2026
	 */
	public function status()
	{
		$this->authorize();
		wp_send_json_success([
			'job' => Job::instance()->progress(),
			'counts' => array_merge(Status::counts(), ['processed' => count(Cleaner::ids())]),
			'savings' => Fields::savings(),
		]);
	}
}
