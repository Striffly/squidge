<?php

/**
 * Queue
 *
 * Queue optimises the images asked from the media
 * library, one after the other, in the background:
 * however many are asked at once, one library runs at
 * a time, and the page does not wait for it.
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

class Queue extends Background
{
	/**
	 * @var string
	 */
	protected $action = 'queue';

	/**
	 * Adds an image to the queue, once, and starts it.
	 *
	 * @param int $id
	 * @since 1.0.0
	 */
	public function add($id)
	{
		if (in_array((int)$id, $this->ids(), true)) {
			return;
		}
		$this->push_to_queue((int)$id)->save()->dispatch();
	}

	/**
	 * Returns the IDs of the images waiting in the
	 * queue, and of the one it processes.
	 *
	 * @return int[]
	 * @since 1.0.0
	 */
	public function ids()
	{
		$ids = [];
		foreach ($this->get_batches() as $batch) {
			$ids = array_merge($ids, array_map('intval', (array)$batch->data));
		}
		return $ids;
	}

	/**
	 * Optimises one image, as the media library does:
	 * a new one, an outdated one brought up to date, an
	 * optimised one made again from its original.
	 *
	 * @param int $id
	 * @return int|false Removes the item from the queue, or keeps it while another request processes the image.
	 * @since 1.0.0
	 */
	protected function task($id)
	{
		if (get_post($id) && Optimiser::optimise($id, null, Optimiser::BACKGROUND) === null) {
			return $this->retry($id);
		}
		return false;
	}
}
