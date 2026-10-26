<?php

/**
 * Logger
 *
 * Responsible for logging out informational and
 * error messages for the plugin.
 *
 * @package     OIO
 * @author      Clément Gayot
 * @author      Ainsley Clark (Squidge, original author)
 * @category    Class
 * @repo        https://github.com/Striffly/octopus-image-optimizer
 *
 */

namespace Octopus\Log;

if (!defined('ABSPATH')) {
	exit; // Exit if accessed directly
}

class Logger
{

	/**
	 * Info logs an information message, with
	 * WP_DEBUG only: one per file optimised, with
	 * the paths of the server.
	 *
	 * @param $message
	 * @since 0.1.0
	 * @date 24/11/2021
	 */
	static function info($message)
	{
		if (defined('WP_DEBUG') && WP_DEBUG) {
			self::log($message, false);
		}
	}

	/**
	 * Error logs an error message.
	 *
	 * @param $message
	 * @since 0.1.0
	 * @date 24/11/2021
	 */
	static function error($message)
	{
		self::log($message, true);
	}

	/**
	 * Log uses the error_log function to log
	 * out an OIO message.
	 *
	 * @param $message
	 * @param $error
	 * @since 0.1.0
	 * @date 24/11/2021
	 */
	static function log($message, $error)
	{
		$type = '[INFO] ';
		if ($error) {
			$type = '[ERROR]';
		}
		error_log(sprintf('OIO: %s - %s', $type, $message));
	}
}
