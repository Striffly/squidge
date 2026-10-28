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

namespace Octopus\Admin;

use Octopus\Media\Cleaner;
use Octopus\Media\Job;
use Octopus\Media\Optimiser;
use Octopus\Media\Stats;
use Octopus\Media\Status;

if (!defined('ABSPATH')) {
	exit; // Exit if accessed directly
}

class JobsAjax
{
	/**
	 * The jobs that can be started: their action, their
	 * mode and the statuses of their images.
	 *
	 * @var array
	 */
	const JOBS = [
		'new' => ['action' => Job::OPTIMISE, 'mode' => Optimiser::MODE_NEW, 'statuses' => [Status::TO_OPTIMISE, Status::ERROR]],
		'outdated' => ['action' => Job::OPTIMISE, 'mode' => Optimiser::MODE_OUTDATED, 'statuses' => [Status::OUTDATED]],
		'all' => ['action' => Job::OPTIMISE, 'mode' => Optimiser::MODE_ALL, 'statuses' => [null]],
		'remove_formats' => ['action' => Job::REMOVE_FORMATS, 'mode' => null, 'statuses' => []],
		'restore' => ['action' => Job::RESTORE, 'mode' => null, 'statuses' => []],
	];

	/**
	 * The commands of a job, by the method of Job they
	 * call, and the states they apply to, see control().
	 *
	 * @var array
	 */
	const COMMANDS = [
		'pause' => ['running'],
		'resume' => ['paused'],
		'cancel' => ['running', 'paused'],
	];

	/**
	 * Adds the AJAX requests.
	 *
	 * @since 1.0.0
	 */
	public function __construct()
	{
		add_action('wp_ajax_octopus_job_start', [$this, 'start']);
		add_action('wp_ajax_octopus_job_status', [$this, 'status']);
		add_action('wp_ajax_octopus_job_control', [$this, 'control']);
	}

	/**
	 * Starts a job on the images of its statuses.
	 *
	 * @since 1.0.0
	 */
	public function start()
	{
		check_ajax_referer(Ajax::NONCE);
		Ajax::authorize();
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
			wp_send_json_error(['message' => __('A job is already running, or there is no image to process.', 'octopus-image-optimizer')], 409);
		}
		$this->status();
	}

	/**
	 * Pauses, resumes or cancels the job the page shows,
	 * in a state the command applies to. Once it changed,
	 * e.g. from the page of another user, or as it ended,
	 * the command is refused, told, and the job sent as
	 * it is, for the page to show it.
	 *
	 * @since 1.0.0
	 */
	public function control()
	{
		check_ajax_referer(Ajax::NONCE);
		Ajax::authorize();
		$command = isset($_POST['command']) ? sanitize_key(wp_unslash($_POST['command'])) : '';
		if (!array_key_exists($command, self::COMMANDS)) {
			wp_send_json_error(null, 400);
		}
		// Its key, see Job::start(), empty for a job started without one.
		$key = isset($_POST['key']) ? sanitize_text_field(wp_unslash($_POST['key'])) : '';
		$job = Job::instance();
		$progress = $job->progress();
		if (!is_array($progress) || (isset($progress['key']) ? $progress['key'] : '') !== $key || !in_array($progress['state'], self::COMMANDS[$command], true)) {
			wp_send_json_error(['message' => __('The job changed meanwhile: your click was not applied. The page now shows it as it is.', 'octopus-image-optimizer')] + self::report(), 409);
		}
		$job->$command();
		wp_send_json_success(self::report());
	}

	/**
	 * Sends the progress of the job, the number of
	 * images by status and the bytes saved.
	 *
	 * @since 1.0.0
	 */
	public function status()
	{
		check_ajax_referer(Ajax::NONCE);
		Ajax::authorize();
		// A job its worker left goes on while the page follows it, see Background::settle().
		Job::instance()->settle();
		// The progress alone, asked every half second while a job runs: the counts take longer on a big media library.
		if (!empty($_POST['light'])) {
			wp_send_json_success(['job' => Job::instance()->progress()]);
		}
		wp_send_json_success(self::report());
	}

	/**
	 * Returns the progress of the job, the number of
	 * images by status and the bytes saved.
	 *
	 * @return array
	 * @since 1.0.0
	 */
	private static function report()
	{
		return [
			'job' => Job::instance()->progress(),
			'counts' => array_merge(Status::counts(), ['processed' => count(Cleaner::ids())]),
			'savings' => Stats::savings(),
		];
	}
}
