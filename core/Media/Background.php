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
		$this->unlock_process();
	}
}
