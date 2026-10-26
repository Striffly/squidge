<?php

/**
 * Lock
 *
 * Lock lets one request at a time process an image:
 * restoring an image while a background job
 * compresses it could leave it compressed without
 * its original.
 *
 * @package     OIO
 * @author      Clément Gayot
 * @category    Class
 * @repo        https://github.com/Striffly/octopus-image-optimizer
 *
 */

namespace Octopus\Package;

if (!defined('ABSPATH')) {
	exit; // Exit if accessed directly
}

class Lock
{
	/**
	 * Prefix of the options holding the locks.
	 *
	 * @var string
	 */
	const PREFIX = 'octopus_lock_';

	/**
	 * Duration after which a lock is stale, in seconds:
	 * the request holding it has stopped.
	 *
	 * @var int
	 */
	const TIMEOUT = 600;

	/**
	 * The images locked by this request: a process
	 * calling another one on the same image goes on.
	 *
	 * @var bool[]
	 */
	private static $held = [];

	/**
	 * Runs a callback on an image, unless another
	 * request is processing it.
	 *
	 * @param int $id
	 * @param callable $callback
	 * @return mixed The result of the callback, or null if
	 *               the image is locked.
	 * @since 1.0.0
	 * @date 25/10/2026
	 */
	public static function run($id, $callback)
	{
		$id = (int)$id;
		if (isset(self::$held[$id])) {
			return $callback();
		}
		if (!self::acquire($id)) {
			return null;
		}
		self::$held[$id] = true;
		try {
			return $callback();
		} finally {
			unset(self::$held[$id]);
			self::release($id);
		}
	}

	/**
	 * Takes the lock of an image. INSERT IGNORE fails
	 * if another request holds it, unlike add_option()
	 * which overwrites it. A stale lock is taken over.
	 *
	 * @param int $id
	 * @return bool
	 * @since 1.0.0
	 * @date 25/10/2026
	 */
	private static function acquire($id)
	{
		global $wpdb;
		$name = self::PREFIX . $id;
		$insert = $wpdb->prepare("INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", $name, time());
		if ($wpdb->query($insert)) {
			return true;
		}
		$deleted = $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value < %d", $name, time() - self::TIMEOUT));
		return $deleted && $wpdb->query($insert);
	}

	/**
	 * Releases the lock of an image.
	 *
	 * @param int $id
	 * @since 1.0.0
	 * @date 25/10/2026
	 */
	private static function release($id)
	{
		global $wpdb;
		$wpdb->delete($wpdb->options, ['option_name' => self::PREFIX . $id]);
	}
}
