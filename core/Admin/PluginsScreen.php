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
		add_action('admin_footer', [$this, 'render_dialog']);
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
		$script = Plugin::enqueue_chain(['utils/Api', 'views/Screen', 'components/Component', 'components/DeactivationWarning', 'plugins'], 'script');
		wp_localize_script($script, 'octopusPlugins', [
			'ajaxurl' => admin_url('admin-ajax.php'),
			'nonce' => wp_create_nonce(self::NONCE),
		]);
		Plugin::enqueue_style('screens/plugins');
	}

	/**
	 * Renders the dialog shown before deactivating OIO
	 * (DeactivationWarning.js): the warning about the
	 * theme, and the one about the originals, its
	 * number of images and their size asked when
	 * deactivating. In the footer, before the scripts.
	 *
	 * @since 1.0.0
	 */
	public function render_dialog()
	{
		$screen = get_current_screen();
		if (!$screen || $screen->id !== 'plugins' || !current_user_can('activate_plugins')) {
			return;
		}
		$theme_calls = $this->theme_calls();
		$restore = __('Restore original images', 'octopus-image-optimizer');
		?>
		<dialog class="octopus-dialog js-octopus-deactivation" data-plugin="<?php echo esc_attr(plugin_basename(OCTOPUS_FILE)); ?>" data-theme-calls="<?php echo $theme_calls ? 'true' : 'false'; ?>">
			<h2><?php esc_html_e('Before deactivating OIO', 'octopus-image-optimizer'); ?></h2>
			<div class="octopus-dialog__body">
				<?php if ($theme_calls) : ?>
					<p>
						<?php
						/* translators: %s: octopus_image() */
						$calls = '<strong>' . sprintf(esc_html__('Your theme calls %s', 'octopus-image-optimizer'), '<code>octopus_image()</code>') . '</strong>';
						/* translators: %s: Your theme calls octopus_image() */
						echo wp_kses(sprintf(esc_html__('%s: the pages using it will break while OIO is deactivated.', 'octopus-image-optimizer'), $calls), ['strong' => [], 'code' => []]);
						?>
					</p>
				<?php endif; ?>
				<div class="js-octopus-deactivation-originals" hidden>
					<p>
						<?php
						echo wp_kses(
							sprintf(
								/* translators: 1: the number of images, 2: the folder of the originals and its size. */
								esc_html__('OIO has optimised %1$s. Deactivating or deleting OIO keeps them as they are: compressed, with their WebP and AVIF versions, and their originals in %2$s.', 'octopus-image-optimizer'),
								'<strong class="js-octopus-deactivation-images"></strong>',
								'<code>uploads/octopus/</code><span class="js-octopus-deactivation-size"></span>'
							),
							['strong' => ['class' => true], 'code' => [], 'span' => ['class' => true]]
						);
						?>
					</p>
					<p>
						<?php
						/* translators: %s: Restore original images */
						echo wp_kses(sprintf(esc_html__('If you are going to delete OIO, run %s first to get your original images back and remove every OIO file.', 'octopus-image-optimizer'), '<strong>' . esc_html($restore) . '</strong>'), ['strong' => []]);
						?>
					</p>
				</div>
			</div>
			<div class="octopus-dialog__actions">
				<a class="button button-primary js-octopus-deactivation-restore" href="<?php echo esc_url(SettingsPage::page_url() . '#overview'); ?>" hidden><?php echo esc_html($restore); ?></a>
				<button type="button" class="button js-octopus-deactivation-proceed"><?php esc_html_e('Deactivate anyway', 'octopus-image-optimizer'); ?></button>
				<button type="button" class="button js-octopus-deactivation-cancel"><?php esc_html_e('Cancel', 'octopus-image-optimizer'); ?></button>
			</div>
		</dialog>
		<?php
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
		Ajax::authorize('activate_plugins');
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
