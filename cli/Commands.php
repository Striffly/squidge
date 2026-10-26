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
 * @repo        https://github.com/Striffly/octopus-image-optimizer
 *
 */

if (!defined('WP_CLI')) {
	return; // Exit, WP CLI is not defined.
}

if (!defined('ABSPATH')) {
	exit; // Exit if accessed directly
}

use OIO\Package\Cleaner;
use OIO\Package\Lock;
use OIO\Package\Optimiser;
use OIO\Package\Stats;
use OIO\Package\Status;
use OIO\Services\JPG;
use OIO\Services\PNG;
use OIO\Services\WebP;
use OIO\Services\AVIF;

/**
 * Optimises the images of the media library, removes
 * their WebP and AVIF files or restores their
 * originals.
 */
class OIO_CLI extends WP_CLI_Command
{

	/**
	 * Prints the version of OIO.
	 *
	 * @since 0.1.0
	 * @date 24/11/2021
	 */
	public function version()
	{
		$this->print_welcome();
		WP_CLI::log(OIO_VERSION);
	}

	/**
	 * Checks that the libraries are installed.
	 *
	 * Without cwebp or avifenc, tells whether GD or
	 * Imagick makes the format instead.
	 *
	 * @since 0.1.0
	 * @date 24/11/2021
	 */
	public function health()
	{
		$this->print_welcome();
		$this->print_health("jpegoptim", JPG::installed());
		$this->print_health(PNG::library(), PNG::installed());
		$this->print_health("pngquant (optional)", PNG::installed('pngquant'));
		$this->print_converter('WebP', WebP::class);
		$this->print_converter('AVIF', AVIF::class);
	}

	/**
	 * Optimises the JPG and PNG images of the media library.
	 *
	 * Compresses them and makes their WebP and AVIF
	 * versions, with the formats and qualities of the
	 * settings page, unless the options below override
	 * them. The images already optimised are only
	 * completed (e.g. a new thumbnail size), unless
	 * --force. Exits with an error if images fail.
	 *
	 * ## OPTIONS
	 *
	 * [--[no-]jpg]
	 * : Compress the JPG images (--jpg) or not (--no-jpg). As on the settings page by default.
	 *
	 * [--[no-]png]
	 * : Compress the PNG images (--png) or not (--no-png). As on the settings page by default.
	 *
	 * [--[no-]webp]
	 * : Make the WebP versions (--webp) or not (--no-webp). As on the settings page by default.
	 *
	 * [--[no-]avif]
	 * : Make the AVIF versions (--avif) or not (--no-avif). As on the settings page by default.
	 *
	 * [--quality=<quality>]
	 * : The quality of the JPG and WebP files, from 0 to 100.
	 *
	 * [--optimization=<level>]
	 * : The optimisation level of the PNG files, from o0 (fastest) to o7 (smallest).
	 *
	 * [--avif-quality=<quality>]
	 * : The quality of the AVIF files, from 0 to 100.
	 *
	 * [--avif-speed=<speed>]
	 * : The speed of avifenc, from 0 (slowest, smallest) to 10. 6 by default: faster speeds make heavier files.
	 *
	 * [--force]
	 * : Optimise again the images already optimised.
	 *
	 * [--regenerate]
	 * : Make the thumbnails again from the originals first. Implies --force.
	 *
	 * [--thumbnails-only]
	 * : Leave the full size images out.
	 *
	 * ## EXAMPLES
	 *
	 *     # Optimise the new images.
	 *     $ wp oio run
	 *
	 *     # Make the WebP files again at quality 75, and nothing else.
	 *     $ wp oio run --webp --no-avif --no-jpg --no-png --quality=75 --force
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
			$on = isset($assoc_args[$format]) ? filter_var($assoc_args[$format], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) : isset($enabled[$format]);
			if ($on === null) {
				WP_CLI::error(sprintf('--%1$s is on or off: use --%1$s or --no-%1$s.', $format));
			}
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
		$failed = 0;
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
				$done = Lock::run($id, function () use ($id, $regenerate, $settings, $force, $thumbnails_only) {
					$metadata = $regenerate ? Optimiser::regenerate($id) : null;
					return Optimiser::attachment($id, $metadata, $settings, $force || $regenerate, $thumbnails_only);
				});
				if ($done === null) {
					WP_CLI::warning(get_the_title($id) . ': skipped, another request is processing it.');
					continue;
				}
				$counter++;
				if (Status::of($id) === Status::ERROR) {
					$failed++;
				}
			}
			$page++;
		} while ($page <= $query->max_num_pages);

		if ($failed) {
			WP_CLI::error(sprintf('Processed %d images, %d with errors: see them in the Media library.', $counter, $failed));
		}
		WP_CLI::success('Successfully processed ' . $counter . ' images.');
	}

	/**
	 * Removes the WebP and AVIF files of all images.
	 *
	 * The JPG and PNG files stay compressed.
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
	 * Restores the original images.
	 *
	 * Removes every file made by OIO (WebP, AVIF,
	 * backups) and its data on the images.
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
		$skipped = 0;
		foreach ($ids as $id) {
			$restored = Cleaner::restore($id);
			if ($restored === null) {
				$skipped++;
				WP_CLI::warning(get_the_title($id) . ': skipped, another request is processing it.');
			} elseif (!$restored) {
				$missing++;
				WP_CLI::warning(get_the_title($id) . ': no original to restore, it stays compressed.');
			}
		}
		WP_CLI::success('Restored ' . (count($ids) - $missing - $skipped) . ' images' . ($missing ? ', ' . $missing . ' had no original' : '') . ($skipped ? ', ' . $skipped . ' skipped' : '') . '.');
	}

	/**
	 * Prints the bytes saved on the media library.
	 *
	 * First measures the images optimised before OIO
	 * measured them, from the sizes of their files:
	 * nothing is encoded.
	 *
	 * ## OPTIONS
	 *
	 * [--rebuild]
	 * : Measure every image again.
	 *
	 * @param $args
	 * @param $assoc_args
	 * @since 1.0.0
	 * @date 25/10/2026
	 */
	public function stats($args, $assoc_args)
	{
		$this->print_welcome();
		$rebuild = isset($assoc_args['rebuild']) && filter_var($assoc_args['rebuild'], FILTER_VALIDATE_BOOLEAN);
		$measured = 0;
		foreach (array_chunk(Cleaner::ids(), 500) as $ids) {
			_prime_post_caches($ids, false, true);
			foreach ($ids as $id) {
				if (!in_array(get_post_mime_type($id), Status::MIMES, true) || (!$rebuild && Stats::of($id) !== null)) {
					continue;
				}
				$done = Lock::run($id, function () use ($id) {
					Stats::save($id);
					return true;
				});
				if ($done === null) {
					WP_CLI::warning(get_the_title($id) . ': skipped, another request is processing it.');
					continue;
				}
				$measured++;
			}
		}
		WP_CLI::log('Measured ' . $measured . ' images.');

		$totals = Stats::totals(true);
		$labels = [
			'original' => 'Originals',
			'compressed' => 'Compressed JPG and PNG',
			'modern' => 'With WebP and AVIF served',
			'extra' => 'WebP and AVIF files',
		];
		$items = [];
		foreach ($labels as $name => $label) {
			$items[] = [
				'Files' => $label,
				'Bytes' => $totals[$name],
				'Size' => Stats::size($totals[$name]),
				'Reduction' => in_array($name, ['compressed', 'modern'], true) ? (string)Stats::reduction($totals, $name) : '',
			];
		}
		WP_CLI\Utils\format_items('table', $items, ['Files', 'Bytes', 'Size', 'Reduction']);

		$served = Stats::served();
		WP_CLI::success(sprintf(
			'%s saved on %d images, %s: the site serves %s.',
			Stats::size(max(0, $totals['original'] - $totals[$served])),
			$totals['images'],
			(string)Stats::reduction($totals, $served),
			$served === 'modern' ? 'the WebP and AVIF' : 'the JPG and PNG, not the WebP and AVIF'
		));
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

	/**
	 * Prints the health information of a format made by
	 * its library, or by the image editor (GD or
	 * Imagick) where the library is missing.
	 *
	 * @param string $format
	 * @param string $service
	 * @since 1.0.0
	 * @date 25/10/2026
	 */
	private function print_converter($format, $service)
	{
		if ($service::installed() || $service::editor() === null) {
			$this->print_health($service::cmd_name(), $service::installed());
			return;
		}
		WP_CLI::success(sprintf('%s active for %s: %s not installed, recommended.', $service::editor(), $format, $service::cmd_name()));
	}
}

/**
 * Add the main OIO command to the WP CLI.
 */
WP_CLI::add_command('oio', 'OIO_CLI');
