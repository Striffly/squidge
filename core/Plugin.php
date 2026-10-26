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

use Octopus\Admin\Fields;
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
	 * @date 24/11/2021
	 * @since 0.1.0
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
	 * @since 0.1.2
	 * @date 05/12/2021
	 */
	private function initialize()
	{
		$base = dirname(__FILE__);
		$this->define('OCTOPUS_URL', plugin_dir_url($base));
		$this->define('OCTOPUS_PATH', plugin_dir_path($base));
		$this->define('OCTOPUS_TEMPLATE_PATH', dirname($base) . DIRECTORY_SEPARATOR . 'templates');
		add_action('init', [Settings::class, 'register']);
		new Fields();
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

	/**
	 * Defines a constant if doesnt already exist.
	 *
	 * @param string $name The constant name.
	 * @param mixed $value The constant value.
	 * @return void
	 * @since 0.1.0
	 * @date    24/11/2021
	 *
	 */
	function define($name, $value = true)
	{
		if (!defined($name)) {
			define($name, $value);
		}
	}
}
