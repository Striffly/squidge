<?php

/**
 * CLI
 *
 * Command for accessing OIO compression
 * functions via the WP CLI.
 *
 * @package     OIO
 * @author      Clément Gayot
 * @author      Ainsley Clark (Squidge, original author)
 * @category    CLI
 * @repo        https://github.com/Striffly/squidge
 *
 */

if (!defined('WP_CLI')) {
	return; // Exit, WP CLI is not defined.
}

if (!defined('ABSPATH')) {
	exit; // Exit if accessed directly
}

use OIO\Package\Cleaner;
use OIO\Package\Optimiser;
use OIO\OIO;
use OIO\Services\JPG;
use OIO\Services\PNG;
use OIO\Services\WebP;
use OIO\Services\AVIF;

class OIO_CLI extends WP_CLI_Command
{

	/**
	 * Returns the version number of the plugin.
	 *
	 * @since 0.1.0
	 * @date 24/11/2021
	 */
	public function version()
	{
		$this->print_welcome();
		WP_CLI::log(OIO::boot()->version);
	}

	/**
	 * Prints health status for each service.
	 *
	 * @since 0.1.0
	 * @date 24/11/2021
	 */
	public function health()
	{
		$this->print_welcome();
		$this->print_health("jpegoptim", JPG::installed());
		$this->print_health("optipng", PNG::installed());
		$this->print_health("cwebp", WebP::installed());
		$this->print_health("avifenc", AVIF::installed());
	}

	/**
	 * Processes all JPG and PNG images of the media
	 * library: converts them to .webp and .avif files
	 * and compresses them.
	 *
	 * The formats and qualities are the ones of the
	 * options page, the args below override them.
	 *
	 * Args:
	 *    - jpg=true|false        JPG compression.
	 *    - png=true|false        PNG compression.
	 *    - webp=true|false       WebP conversion.
	 *    - avif=true|false       AVIF conversion.
	 *    - quality=80            The quality of JPG compression and WebP conversion.
	 *    - optimization=o2       Optimization of PNG images (o0 to o7).
	 *    - avif-quality=70       The quality of AVIF conversion.
	 *    - avif-speed=6          The speed of the AVIF encoder (0 to 10): 6 is best, faster speeds make larger files.
	 *    - force=false           Force reoptimization of already optimized images.
	 *    - regenerate=false      Make the thumbnails again from the originals first (implies force).
	 *    - thumbnails-only=false Only optimize thumbnails.
	 *
	 * @param $args
	 * @param $assoc_args
	 * @since 0.1.0
	 * @date 24/11/2021
	 */
	public function run($args, $assoc_args)
	{
		$this->print_welcome();
		Optimiser::$context = 'cli';

		$settings = Optimiser::settings(false);
		$enabled = Optimiser::settings();
		foreach (array_keys($settings) as $format) {
			$on = isset($assoc_args[$format]) ? filter_var($assoc_args[$format], FILTER_VALIDATE_BOOLEAN) : isset($enabled[$format]);
			if (!$on) {
				unset($settings[$format]);
			}
		}
		if (isset($assoc_args['quality'])) {
			foreach (['jpg', 'webp'] as $format) {
				if (isset($settings[$format])) {
					$settings[$format]['quality'] = $assoc_args['quality'];
				}
			}
		}
		if (isset($settings['png'], $assoc_args['optimization'])) {
			$settings['png']['optimization'] = $assoc_args['optimization'];
		}
		if (isset($settings['avif'], $assoc_args['avif-quality'])) {
			$settings['avif']['quality'] = $assoc_args['avif-quality'];
		}
		if (isset($settings['avif'], $assoc_args['avif-speed'])) {
			$settings['avif']['speed'] = $assoc_args['avif-speed'];
		}
		$force = isset($assoc_args['force']) && filter_var($assoc_args['force'], FILTER_VALIDATE_BOOLEAN);
		$thumbnails_only = isset($assoc_args['thumbnails-only']) && filter_var($assoc_args['thumbnails-only'], FILTER_VALIDATE_BOOLEAN);
		$regenerate = isset($assoc_args['regenerate']) && filter_var($assoc_args['regenerate'], FILTER_VALIDATE_BOOLEAN);

		WP_CLI::log('Formats: ' . (empty($settings) ? 'none' : implode(', ', array_keys($settings))));

		$counter = 0;
		$page = 1;
		do {
			$query = new WP_Query([
				'post_type' => 'attachment',
				'post_mime_type' => ['image/jpeg', 'image/png'],
				'post_status' => 'inherit',
				'posts_per_page' => 500,
				'paged' => $page,
				'fields' => 'ids',
				'orderby' => 'ID',
				'order' => 'ASC',
			]);
			foreach ($query->posts as $id) {
				WP_CLI::log(WP_CLI::colorize("%BProcessing image: %n") . get_the_title($id));
				$metadata = $regenerate ? Optimiser::regenerate($id) : null;
				Optimiser::attachment($id, $metadata, $settings, $force || $regenerate, $thumbnails_only);
				$counter++;
			}
			$page++;
		} while ($page <= $query->max_num_pages);

		WP_CLI::success('Successfully processed ' . $counter . ' images. ');
	}

	/**
	 * Removes the WebP and AVIF files of all images. The
	 * JPG and PNG files stay compressed.
	 *
	 * @since 1.0.0
	 * @date 25/10/2026
	 */
	public function clean()
	{
		$this->print_welcome();
		Optimiser::$context = 'cli';
		$ids = Cleaner::ids();
		foreach ($ids as $id) {
			Cleaner::remove_formats($id);
		}
		WP_CLI::success('Removed the WebP and AVIF files of ' . count($ids) . ' images.');
	}

	/**
	 * Restores the original images and removes every
	 * file made by OIO (WebP, AVIF, backups).
	 *
	 * @since 1.0.0
	 * @date 25/10/2026
	 */
	public function restore()
	{
		$this->print_welcome();
		Optimiser::$context = 'cli';
		$ids = Cleaner::ids();
		$missing = 0;
		foreach ($ids as $id) {
			if (!Cleaner::restore($id)) {
				$missing++;
				WP_CLI::warning(get_the_title($id) . ': no original to restore, it stays compressed.');
			}
		}
		WP_CLI::success('Restored ' . (count($ids) - $missing) . ' images' . ($missing ? ', ' . $missing . ' had no original.' : '.'));
	}

	/**
	 * Prints CLI welcome message.
	 *
	 * @since 0.1.0
	 * @date 24/11/2021
	 */
	private function print_welcome()
	{
		WP_CLI::line(WP_CLI::colorize('%GWelcome to the OIO CLI...%n' . PHP_EOL));
	}

	/**
	 * Prints the health information for a service.
	 *
	 * @param $type
	 * @param $active
	 * @since 0.1.0
	 * @date 24/11/2021
	 */
	private function print_health($type, $active)
	{
		if ($active) {
			WP_CLI::success($type . " active.");
			return;
		}
		WP_CLI::log(WP_CLI::colorize("%RError: %n") . $type . ' not installed, visit docs.');
	}
}

/**
 * Add the main OIO command to the WP CLI.
 */
WP_CLI::add_command('oio', 'OIO_CLI');
