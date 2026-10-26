<?php

/**
 * Plugins
 *
 * Plugins warns on the plugins page before OIO
 * is deactivated: deleting it then does not restore
 * the originals, and the theme may call
 * octopus_image(). WordPress only shows "Delete" for
 * inactive plugins, when OIO no longer runs.
 *
 * @package     OIO
 * @author      Clément Gayot
 * @category    Admin
 * @repo        https://github.com/Striffly/octopus-image-optimizer
 *
 */

namespace Octopus\Admin;

use Octopus\Media\Cleaner;
use Octopus\Media\Files;
use Octopus\Plugin;

if (!defined('ABSPATH')) {
	exit; // Exit if accessed directly
}

class PluginsScreen
{
	/**
	 * Nonce action of the request.
	 *
	 * @var string
	 */
	const NONCE = 'octopus_uninstall_info';

	/**
	 * Transient caching the theme files scan.
	 *
	 * @var string
	 */
	const THEME_TRANSIENT = 'octopus_theme_calls';

	/**
	 * Adds the script of the plugins page and its request.
	 *
	 * @since 1.0.0
	 */
	public function __construct()
	{
		add_action('admin_enqueue_scripts', [$this, 'scripts']);
		add_action('wp_ajax_' . self::NONCE, [$this, 'info']);
		add_action('switch_theme', [$this, 'forget_theme']);
		add_filter('plugin_action_links_' . plugin_basename(OCTOPUS_FILE), [$this, 'settings_link']);
		add_filter('plugin_row_meta', [$this, 'support_link'], 10, 2);
	}

	/**
	 * Adds a link to the settings page, next to
	 * Deactivate in the list of the plugins.
	 *
	 * @param string[] $links
	 * @return string[]
	 * @since 1.0.0
	 */
	public function settings_link($links)
	{
		$links[] = sprintf('<a href="%s">%s</a>', esc_url(SettingsPage::page_url()), esc_html__('Settings', 'octopus-image-optimizer'));
		return $links;
	}

	/**
	 * Adds a link to buy a coffee to the author, after
	 * the details of OIO in the list of the plugins: it
	 * opens the bottom of the settings page.
	 *
	 * @param string[] $meta
	 * @param string $file
	 * @return string[]
	 * @since 1.0.0
	 */
	public function support_link($meta, $file)
	{
		if ($file === plugin_basename(OCTOPUS_FILE) && current_user_can('manage_options')) {
			$meta[] = sprintf('<a href="%s">☕ %s</a>', esc_url(SettingsPage::page_url() . '#support'), esc_html__('Buy me a coffee', 'octopus-image-optimizer'));
		}
		return $meta;
	}

	/**
	 * Enqueues the warnings on the plugins page.
	 *
	 * @param string $hook
	 * @since 1.0.0
	 */
	public function scripts($hook)
	{
		if ($hook !== 'plugins.php' || !current_user_can('activate_plugins')) {
			return;
		}
		$script = Plugin::enqueue_script('plugins', ['wp-i18n']);
		wp_localize_script($script, 'octopusPlugins', [
			'plugin' => plugin_basename(OCTOPUS_FILE),
			'ajaxurl' => admin_url('admin-ajax.php'),
			'nonce' => wp_create_nonce(self::NONCE),
			'restoreUrl' => SettingsPage::page_url() . '#overview',
			'themeCalls' => $this->theme_calls(),
		]);
		Plugin::enqueue_style('screens/plugins');
	}

	/**
	 * Sends the number of images OIO processed and
	 * the size of the kept originals, shown before
	 * deactivating OIO.
	 *
	 * @since 1.0.0
	 */
	public function info()
	{
		check_ajax_referer(self::NONCE);
		if (!current_user_can('activate_plugins')) {
			wp_send_json_error(null, 403);
		}
		$dir = Files::backup_dir();
		wp_send_json_success([
			'images' => count(Cleaner::ids()),
			'size' => is_dir($dir) ? size_format($this->dir_size($dir)) : '',
		]);
	}

	/**
	 * Returns the size of a folder.
	 *
	 * @param string $dir
	 * @return int
	 * @since 1.0.0
	 */
	private function dir_size($dir)
	{
		$size = 0;
		$files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
		foreach ($files as $file) {
			$size += $file->getSize();
		}
		return $size;
	}

	/**
	 * Determines if the active theme, or its parent,
	 * calls octopus_image(): the pages using it break
	 * while OIO is deactivated. Cached for 12 hours.
	 *
	 * @return bool
	 * @since 1.0.0
	 */
	private function theme_calls()
	{
		$cached = get_transient(self::THEME_TRANSIENT);
		if ($cached !== false) {
			return $cached === 'yes';
		}
		$calls = false;
		foreach (array_unique([get_stylesheet_directory(), get_template_directory()]) as $dir) {
			if ($this->dir_calls($dir)) {
				$calls = true;
				break;
			}
		}
		set_transient(self::THEME_TRANSIENT, $calls ? 'yes' : 'no', 12 * HOUR_IN_SECONDS);
		return $calls;
	}

	/**
	 * Determines if a PHP or Blade file of a folder
	 * calls octopus_image(), outside the dependencies.
	 *
	 * @param string $dir
	 * @return bool
	 * @since 1.0.0
	 */
	private function dir_calls($dir)
	{
		if (!is_dir($dir)) {
			return false;
		}
		$directories = new \RecursiveCallbackFilterIterator(
			new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
			function ($file) {
				return !$file->isDir() || !in_array($file->getFilename(), ['node_modules', 'vendor', '.git'], true);
			}
		);
		foreach (new \RecursiveIteratorIterator($directories) as $file) {
			if ($file->getExtension() === 'php' && strpos(file_get_contents($file->getPathname()), 'octopus_image(') !== false) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Scans the new theme next time.
	 *
	 * @since 1.0.0
	 */
	public function forget_theme()
	{
		delete_transient(self::THEME_TRANSIENT);
	}
}
