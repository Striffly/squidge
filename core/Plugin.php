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

use Octopus\Admin\JobsAjax;
use Octopus\Admin\MediaColumn;
use Octopus\Admin\Notices;
use Octopus\Admin\PluginsScreen;
use Octopus\Admin\SettingsAjax;
use Octopus\Admin\SettingsPage;
use Octopus\Delivery\Display;
use Octopus\Delivery\Htaccess;
use Octopus\Delivery\Test;
use Octopus\Media\Job;
use Octopus\Media\Lock;
use Octopus\Media\Queue;
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
		add_action('init', [$this, 'load_translations']);
		add_action('init', [Settings::class, 'register']);
		add_action('octopus_working', [Lock::class, 'beat']);
		new SettingsPage();
		new SettingsAjax();
		new UploadHooks();
		new Notices();
		new MediaColumn();
		new PluginsScreen();
		new Htaccess();
		new Test();
		// The background jobs need the dependencies of composer install.
		if (class_exists('Octopus_Vendor_WP_Background_Process')) {
			Job::instance();
			Queue::instance();
			new JobsAjax();
		}
		new Display();
	}

	/**
	 * Loads the translations shipped in /languages,
	 * e.g. French. Those of translate.wordpress.org,
	 * in wp-content/languages, come first.
	 *
	 * @since 1.0.0
	 */
	public function load_translations()
	{
		// phpcs:ignore PluginCheck.CodeAnalysis.DiscouragedFunctions.load_plugin_textdomainFound -- Loads the translations shipped with OIO, also installed from GitHub.
		load_plugin_textdomain('octopus-image-optimizer', false, dirname(plugin_basename(OCTOPUS_FILE)) . '/languages');
	}

	/**
	 * Enqueues a stylesheet of assets/css, e.g.
	 * base/tokens as octopus-base-tokens. Its version
	 * is its last change, so that browsers load it again
	 * after an update.
	 *
	 * @param string $path Relative to assets/css, without .css.
	 * @param string[] $deps
	 * @return string The handle.
	 * @since 1.0.0
	 */
	public static function enqueue_style($path, $deps = [])
	{
		$handle = self::handle($path);
		$file = 'assets/css/' . $path . '.css';
		wp_enqueue_style($handle, OCTOPUS_URL . $file, $deps, filemtime(OCTOPUS_PATH . $file));
		return $handle;
	}

	/**
	 * Enqueues a script of assets/js in the footer, with
	 * its translations when it uses wp.i18n.
	 *
	 * @param string $path Relative to assets/js, without .js.
	 * @param string[] $deps
	 * @return string The handle.
	 * @since 1.0.0
	 */
	public static function enqueue_script($path, $deps = [])
	{
		$handle = self::handle($path);
		$file = 'assets/js/' . $path . '.js';
		wp_enqueue_script($handle, OCTOPUS_URL . $file, $deps, filemtime(OCTOPUS_PATH . $file), true);
		if (in_array('wp-i18n', $deps, true)) {
			wp_set_script_translations($handle, 'octopus-image-optimizer', OCTOPUS_PATH . 'languages');
		}
		return $handle;
	}

	/**
	 * Enqueues files in their order, each one after
	 * the previous one: the stylesheets cascade, the
	 * scripts use the classes loaded before them.
	 *
	 * @param string[] $paths
	 * @param string $type style or script.
	 * @param string[] $deps Of the first file.
	 * @return string The handle of the last file.
	 * @since 1.0.0
	 */
	public static function enqueue_chain($paths, $type, $deps = [])
	{
		$handle = '';
		foreach ($paths as $path) {
			$handle = $type === 'style' ? self::enqueue_style($path, $deps) : self::enqueue_script($path, array_merge(['wp-i18n'], $deps));
			$deps = [$handle];
		}
		return $handle;
	}

	/**
	 * Returns the handle of an asset, from its path.
	 *
	 * @param string $path
	 * @return string
	 * @since 1.0.0
	 */
	private static function handle($path)
	{
		return 'octopus-' . strtolower(str_replace('/', '-', $path));
	}
}
