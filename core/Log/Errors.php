<?php

/**
 * Errors
 *
 * Errors keeps the failures of the image libraries,
 * per attachment (shown right after an upload) and
 * as a list of recent errors (shown as an admin
 * notice until dismissed).
 *
 * @package     OIO
 * @version     0.1.4
 * @author      Clément Gayot
 * @category    Log
 * @repo        https://github.com/Striffly/squidge
 *
 */

namespace OIO\Log;

if (!defined('ABSPATH')) {
	exit; // Exit if accessed directly
}

class Errors
{
	/**
	 * Post meta key of the errors of an attachment,
	 * grouped by library.
	 *
	 * @var string
	 */
	const META_KEY = '_oio_errors';

	/**
	 * Option key of the recent errors.
	 *
	 * @var string
	 */
	const OPTION = 'oio_errors';

	/**
	 * Number of recent errors kept.
	 *
	 * @var int
	 */
	const MAX = 20;

	/**
	 * Records a library failure on a file.
	 *
	 * @param int $id Attachment ID, 0 if unknown.
	 * @param string $tool
	 * @param string $filepath
	 * @param string $message
	 * @since 0.1.5
	 * @date 25/10/2026
	 */
	public static function add($id, $tool, $filepath, $message)
	{
		$file = basename($filepath);
		Logger::error(sprintf('%s failed on %s: %s', $tool, $filepath, $message));
		if (defined('WP_CLI') && WP_CLI) {
			\WP_CLI::warning(sprintf('%s failed on %s: %s', $tool, $file, $message));
		}

		if ($id) {
			$meta = get_post_meta($id, self::META_KEY, true);
			if (!is_array($meta)) {
				$meta = [];
			}
			$meta[$tool][] = ['file' => $file, 'message' => $message];
			update_post_meta($id, self::META_KEY, $meta);
		}

		$recent = self::recent();
		array_unshift($recent, [
			'time' => time(),
			'id' => (int)$id,
			'tool' => $tool,
			'file' => $file,
			'message' => $message,
		]);
		update_option(self::OPTION, array_slice($recent, 0, self::MAX), false);
	}

	/**
	 * Returns the errors of an attachment, as a flat
	 * list of tool, file and message.
	 *
	 * @param int $id
	 * @return array
	 * @since 0.1.5
	 * @date 25/10/2026
	 */
	public static function for_attachment($id)
	{
		$meta = get_post_meta($id, self::META_KEY, true);
		if (!is_array($meta)) {
			return [];
		}
		$errors = [];
		foreach ($meta as $tool => $failures) {
			foreach ($failures as $failure) {
				$errors[] = ['id' => (int)$id, 'tool' => $tool, 'file' => $failure['file'], 'message' => $failure['message']];
			}
		}
		return $errors;
	}

	/**
	 * Determines if a library failed on an attachment.
	 *
	 * @param int $id
	 * @param string $tool
	 * @return bool
	 * @since 0.1.5
	 * @date 25/10/2026
	 */
	public static function has($id, $tool)
	{
		$meta = get_post_meta($id, self::META_KEY, true);
		return is_array($meta) && !empty($meta[$tool]);
	}

	/**
	 * Clears the errors of a library on an attachment,
	 * before it processes it again.
	 *
	 * @param int $id
	 * @param string $tool
	 * @since 0.1.5
	 * @date 25/10/2026
	 */
	public static function clear_attachment($id, $tool)
	{
		$meta = get_post_meta($id, self::META_KEY, true);
		if (!is_array($meta) || !isset($meta[$tool])) {
			return;
		}
		unset($meta[$tool]);
		if (empty($meta)) {
			delete_post_meta($id, self::META_KEY);
			return;
		}
		update_post_meta($id, self::META_KEY, $meta);
	}

	/**
	 * Returns the recent errors, newest first.
	 *
	 * @return array
	 * @since 0.1.5
	 * @date 25/10/2026
	 */
	public static function recent()
	{
		$recent = get_option(self::OPTION, []);
		return is_array($recent) ? $recent : [];
	}

	/**
	 * Clears the recent errors, once dismissed.
	 *
	 * @since 0.1.5
	 * @date 25/10/2026
	 */
	public static function clear_recent()
	{
		delete_option(self::OPTION);
	}
}
