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
	 * file (see touch()), and every BEAT seconds while
	 * it works (see beat()), so it has stopped, e.g.
	 * with PHP. The image is then free again, for the
	 * next job.
	 *
	 * @var int
	 */
	const TIMEOUT = 60;

	/**
	 * The seconds between two refreshes of the locks a
	 * request holds while it works.
	 *
	 * @var int
	 */
	const BEAT = 10;

	/**
	 * When this request last refreshed its locks, see
	 * beat().
	 *
	 * @var float
	 */
	private static $beaten = 0.0;

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
		$value = self::take(self::PREFIX . $id, self::TIMEOUT);
		if ($value === null) {
			return null;
		}
		self::$held[$id] = $value;
		try {
			return $callback();
		} finally {
			self::free(self::PREFIX . $id, self::$held[$id]);
			unset(self::$held[$id]);
		}
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
		return self::alive(self::PREFIX . (int)$id, self::TIMEOUT);
	}

	/**
	 * Returns the seconds the lock of an image may still
	 * hold it, at most: once stale, unless the request
	 * holding it releases it before. 0 if it is free.
	 *
	 * @param int $id
	 * @return int
	 * @since 1.0.0
	 */
	public static function left($id)
	{
		$age = self::age($id);
		return $age === null ? 0 : max(0, (int)ceil(self::TIMEOUT - $age));
	}

	/**
	 * Returns the seconds since the lock of an image was
	 * taken or last refreshed (see beat()), null if it
	 * is free.
	 *
	 * @param int $id
	 * @return float|null
	 * @since 1.0.0
	 */
	public static function age($id)
	{
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- See take().
		$value = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::PREFIX . (int)$id));
		return $value === null ? null : microtime(true) - (float)$value;
	}

	/**
	 * Deletes the locks of the images gone stale, e.g.
	 * of a worker PHP stopped: they hold nothing, and
	 * would stay until the image is processed again.
	 * Not the locks of the workers, with their own
	 * timeout (see Background).
	 *
	 * @since 1.0.0
	 */
	public static function sweep()
	{
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- See take().
		$wpdb->query($wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s AND option_name NOT LIKE %s AND option_value < %d",
			$wpdb->esc_like(self::PREFIX) . '%',
			$wpdb->esc_like(self::PREFIX . 'worker_') . '%',
			time() - self::TIMEOUT
		));
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
		$id = (int)$id;
		if (isset(self::$held[$id])) {
			self::$held[$id] = self::refresh(self::PREFIX . $id, self::$held[$id]) ?? self::$held[$id];
		}
	}

	/**
	 * Refreshes the locks of the images this request
	 * holds, every BEAT seconds at most, while it works:
	 * on octopus_working, as the libraries run and
	 * WordPress makes the thumbnails.
	 *
	 * @since 1.0.0
	 */
	public static function beat()
	{
		if (!self::$held || microtime(true) - self::$beaten < self::BEAT) {
			return;
		}
		foreach (array_keys(self::$held) as $id) {
			self::touch($id);
		}
		self::$beaten = microtime(true);
	}

	/**
	 * Takes a lock by its name, e.g. of an image or of
	 * the worker of a queue (see Background). INSERT
	 * IGNORE fails if another request holds it, unlike
	 * add_option() which overwrites it. A lock stale for
	 * longer than the timeout is taken over. Its value
	 * is the time it was taken, to the microsecond: the
	 * one of this request only.
	 *
	 * @param string $name
	 * @param int $timeout In seconds.
	 * @return string|null The value of the lock, null if another request holds it.
	 * @since 1.0.0
	 */
	public static function take($name, $timeout)
	{
		global $wpdb;
		$value = self::now();
		$insert = $wpdb->prepare("INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", $name, $value);
		// phpcs:disable WordPress.DB.DirectDatabaseQuery -- The lock is atomic in the database, never cached: the Options API reads before it writes.
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared above.
		if ($wpdb->query($insert)) {
			return $value;
		}
		$deleted = $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value < %d", $name, time() - $timeout));
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared above.
		return $deleted && $wpdb->query($insert) ? $value : null;
		// phpcs:enable WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Determines if a lock is held: there, and not
	 * stale.
	 *
	 * @param string $name
	 * @param int $timeout In seconds.
	 * @return bool
	 * @since 1.0.0
	 */
	public static function alive($name, $timeout)
	{
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- See take().
		$value = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name));
		return $value !== null && (float)$value >= time() - $timeout;
	}

	/**
	 * Refreshes a lock this request holds, if it is
	 * still its own.
	 *
	 * @param string $name
	 * @param string $value Its value, see take().
	 * @return string|null Its new value, null if another request took it over.
	 * @since 1.0.0
	 */
	public static function refresh($name, $value)
	{
		global $wpdb;
		$new = self::now();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- See take().
		return $wpdb->update($wpdb->options, ['option_value' => $new], ['option_name' => $name, 'option_value' => $value]) ? $new : null;
	}

	/**
	 * Releases a lock, if it is still the one of this
	 * request: taken over once stale, it is the one of
	 * another request. Without value, whoever holds it.
	 *
	 * @param string $name
	 * @param string|null $value Its value, see take().
	 * @since 1.0.0
	 */
	public static function free($name, $value = null)
	{
		global $wpdb;
		$where = $value === null ? ['option_name' => $name] : ['option_name' => $name, 'option_value' => $value];
		$wpdb->delete($wpdb->options, $where); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- See take().
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
