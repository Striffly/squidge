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

use Octopus\Package\Cleaner;
use Octopus\Package\Service;

if (!defined('ABSPATH')) {
	exit; // Exit if accessed directly
}

class Plugins
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
	 * @date 25/10/2026
	 */
	public function __construct()
	{
		add_action('admin_enqueue_scripts', [$this, 'scripts']);
		add_action('wp_ajax_' . self::NONCE, [$this, 'info']);
		add_action('switch_theme', [$this, 'forget_theme']);
	}

	/**
	 * Enqueues the warnings on the plugins page.
	 *
	 * @param string $hook
	 * @since 1.0.0
	 * @date 25/10/2026
	 */
	public function scripts($hook)
	{
		if ($hook !== 'plugins.php' || !current_user_can('activate_plugins')) {
			return;
		}
		wp_enqueue_script('octopus-plugins', OCTOPUS_URL . 'assets/plugins.js', [], filemtime(OCTOPUS_PATH . 'assets/plugins.js'), true);
		wp_localize_script('octopus-plugins', 'octopusPlugins', [
			'plugin' => plugin_basename(OCTOPUS_PATH . 'octopus-image-optimizer.php'),
			'ajaxurl' => admin_url('admin-ajax.php'),
			'nonce' => wp_create_nonce(self::NONCE),
			'restoreUrl' => Fields::page_url() . '#overview',
			'themeCalls' => $this->theme_calls(),
		]);
		wp_add_inline_style('wp-admin', '.octopus-dialog{max-width:520px;padding:24px;border:0;border-radius:8px;box-shadow:0 10px 40px rgba(0,0,0,.25)}.octopus-dialog::backdrop{background:rgba(0,0,0,.4)}.octopus-dialog h2{margin-top:0}.octopus-dialog__actions{display:flex;flex-wrap:wrap;gap:8px;margin-top:20px}');
	}

	/**
	 * Sends the number of images OIO processed and
	 * the size of the kept originals, shown before
	 * deactivating OIO.
	 *
	 * @since 1.0.0
	 * @date 25/10/2026
	 */
	public function info()
	{
		check_ajax_referer(self::NONCE);
		if (!current_user_can('activate_plugins')) {
			wp_send_json_error(null, 403);
		}
		$dir = Service::backup_dir();
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
	 * @date 25/10/2026
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
	 * @date 25/10/2026
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
	 * @date 25/10/2026
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
	 * @date 25/10/2026
	 */
	public function forget_theme()
	{
		delete_transient(self::THEME_TRANSIENT);
	}
}
