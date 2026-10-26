<?php

/**
 * Plugin
 *
 * Main class of OIO: boots it once.
 *
 * @package     OIO
 * @author      Clément Gayot
 * @author      Ainsley Clark (Squidge, original author)
 * @category    Class
 * @repo        https://github.com/Striffly/octopus-image-optimizer
 *
 */

namespace Octopus;

use Octopus\Admin\SettingsPage;
use Octopus\Admin\JobsAjax;
use Octopus\Admin\MediaColumn;
use Octopus\Admin\Notices;
use Octopus\Admin\PluginsScreen;
use Octopus\Delivery\Display;
use Octopus\Delivery\Htaccess;
use Octopus\Delivery\Test;
use Octopus\Media\Job;
use Octopus\Media\UploadHooks;

if (!defined('ABSPATH')) {
	exit; // Exit if accessed directly
}

final class Plugin
{
	/**
	 * The instance of OIO.
	 *
	 * @var null
	 */
	private static $instance = NULL;

	/**
	 * Boots OIO and initialises the
	 * static instance, once.
	 *
	 * @return OIO|null
	 * @since 1.0.0
	 */
	public static function boot()
	{
		if (self::$instance == null) {
			$plugin = new Plugin();
			$plugin->initialize();
			self::$instance = $plugin;
		}
		return self::$instance;
	}

	/**
	 * Sets up the OIO plugin.
	 *
	 * @param void
	 * @return void
	 * @since 1.0.0
	 */
	private function initialize()
	{
		$base = dirname(__FILE__);
		define('OCTOPUS_URL', plugin_dir_url($base));
		define('OCTOPUS_PATH', plugin_dir_path($base));
		define('OCTOPUS_TEMPLATE_PATH', dirname($base) . DIRECTORY_SEPARATOR . 'templates');
		add_action('init', [Settings::class, 'register']);
		new SettingsPage();
		new UploadHooks();
		new Notices();
		new MediaColumn();
		new PluginsScreen();
		new Htaccess();
		new Test();
		// The background jobs need the dependencies of composer install.
		if (class_exists('Octopus_Vendor_WP_Background_Process')) {
			Job::instance();
			new JobsAjax();
		}
		new Display();
	}
}
