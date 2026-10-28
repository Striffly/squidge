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

namespace Octopus\Media;

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
	 * the request holding it refreshes it before each
	 * file (see touch()), a library takes 2 minutes at
	 * most on one (Libraries::TIMEOUT), so it has
	 * stopped.
	 *
	 * @var int
	 */
	const TIMEOUT = 300;

	/**
	 * The images locked by this request, with the value
	 * of their lock: a process calling another one on
	 * the same image goes on, and only this request
	 * refreshes or releases them.
	 *
	 * @var string[]
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
	 */
	public static function run($id, $callback)
	{
		$id = (int)$id;
		if (isset(self::$held[$id])) {
			return $callback();
		}
		$value = self::acquire($id);
		if ($value === null) {
			return null;
		}
		self::$held[$id] = $value;
		try {
			return $callback();
		} finally {
			self::release($id, self::$held[$id]);
			unset(self::$held[$id]);
		}
	}

	/**
	 * Takes the lock of an image. INSERT IGNORE fails
	 * if another request holds it, unlike add_option()
	 * which overwrites it. A stale lock is taken over.
	 * Its value is the time it was taken, to the
	 * microsecond: the one of this request only.
	 *
	 * @param int $id
	 * @return string|null The value of the lock, null if another request holds it.
	 * @since 1.0.0
	 */
	private static function acquire($id)
	{
		global $wpdb;
		$name = self::PREFIX . $id;
		$value = self::now();
		$insert = $wpdb->prepare("INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", $name, $value);
		// phpcs:disable WordPress.DB.DirectDatabaseQuery -- The lock is atomic in the database, never cached: the Options API reads before it writes.
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared above.
		if ($wpdb->query($insert)) {
			return $value;
		}
		$deleted = $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value < %d", $name, time() - self::TIMEOUT));
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared above.
		return $deleted && $wpdb->query($insert) ? $value : null;
		// phpcs:enable WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Determines if a request is processing an image:
	 * its lock is there, and not stale.
	 *
	 * @param int $id
	 * @return bool
	 * @since 1.0.0
	 */
	public static function locked($id)
	{
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- See acquire().
		$value = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::PREFIX . (int)$id));
		return $value !== null && (float)$value >= time() - self::TIMEOUT;
	}

	/**
	 * Refreshes the lock of an image this request
	 * holds, so that it does not go stale while the
	 * request processes it.
	 *
	 * @param int $id
	 * @since 1.0.0
	 */
	public static function touch($id)
	{
		global $wpdb;
		$id = (int)$id;
		if (!isset(self::$held[$id])) {
			return;
		}
		$value = self::now();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- See acquire().
		if ($wpdb->update($wpdb->options, ['option_value' => $value], ['option_name' => self::PREFIX . $id, 'option_value' => self::$held[$id]])) {
			self::$held[$id] = $value;
		}
	}

	/**
	 * Releases the lock of an image, if it is still the
	 * one of this request: taken over once stale, it is
	 * the one of another request.
	 *
	 * @param int $id
	 * @param string $value
	 * @since 1.0.0
	 */
	private static function release($id, $value)
	{
		global $wpdb;
		$wpdb->delete($wpdb->options, ['option_name' => self::PREFIX . $id, 'option_value' => $value]); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- See acquire().
	}

	/**
	 * Returns the time, to the microsecond, as the
	 * value of a lock.
	 *
	 * @return string
	 * @since 1.0.0
	 */
	private static function now()
	{
		return sprintf('%.6F', microtime(true));
	}
}
