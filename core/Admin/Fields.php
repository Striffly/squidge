<?php

/**
 * Fields
 *
 * The fields of the settings, as the options page
 * shows them: their labels, help and controls by
 * section, and the formats with the state of their
 * libraries. What they hold is in Octopus\Settings.
 *
 * @package     OIO
 * @author      Clément Gayot
 * @author      Ainsley Clark (Squidge, original author)
 * @category    Class
 * @repo        https://github.com/Striffly/octopus-image-optimizer
 *
 */

namespace Octopus\Admin;

use Octopus\Encoders\Formats;
use Octopus\Settings;

if (!defined('ABSPATH')) {
	exit; // Exit if accessed directly
}

class Fields
{
	/**
	 * Returns the format sections (JPG, PNG, WebP,
	 * AVIF) with the state of their library: installed,
	 * version, the engine making the format (the
	 * library, or GD or Imagick for WebP and AVIF
	 * without it, null if none), the optional
	 * libraries (e.g. pngquant), and whether the
	 * better library replacing the one installed is
	 * missing (oxipng, for optipng).
	 *
	 * @param array $sections
	 * @return array
	 * @since 1.0.0
	 */
	public static function formats($sections)
	{
		$formats = [];
		foreach (Formats::ENCODERS as $id => $service) {
			$section = $sections[$id];
			$section['title'] = $service::TITLE;
			$section['installed'] = $service::installed();
			$section['version'] = $service::version_number();
			$section['enabled'] = Settings::get($id . '_enable');
			$section['library'] = $service::library();
			$section['engine'] = $service::engine();
			$section['preferred'] = $service::PREFERRED;
			$section['upgrade'] = $section['installed'] && $service::PREFERRED && !$service::installed($service::PREFERRED);
			$section['extras'] = [];
			foreach ($service::OPTIONAL as $name) {
				$section['extras'][$name] = $service::installed($name);
			}
			$formats[$id] = $section;
		}
		return $formats;
	}

	/**
	 * Returns the option fields, grouped by section.
	 *
	 * @return array
	 * @since 1.0.0
	 */
	public static function sections()
	{
		return [
			'jpg' => [
				'fields' => [
					'jpg_enable' => [
						'type' => 'checkbox',
						'label' => __('Compress JPG images', 'octopus-image-optimizer'),
						'help' => __('Compresses the JPG images in place with jpegoptim, and makes them progressive. Their originals are kept.', 'octopus-image-optimizer'),
					],
					'jpg_quality' => [
						'type' => 'number',
						'label' => __('Quality', 'octopus-image-optimizer'),
						/* translators: 1: the minimum, 2: the maximum, 3: the default. */
						'help' => self::bounds(__('From %1$d to %2$d, %3$d by default.', 'octopus-image-optimizer'), 'jpg_quality'),
					],
				],
			],
			'png' => [
				// What the optional libraries are for, see AbstractEncoder::OPTIONAL.
				'uses' => ['pngquant' => __('lossy compression', 'octopus-image-optimizer')],
				'preference' => __('optipng compresses the PNG images for now. Once installed, oxipng replaces it on its own: the files are lighter at the same level, and it is a little faster.', 'octopus-image-optimizer'),
				'fields' => [
					'png_enable' => [
						'type' => 'checkbox',
						'label' => __('Compress PNG images', 'octopus-image-optimizer'),
						'help' => __('Compresses the PNG images in place with oxipng or optipng, after pngquant when lossy. Their originals are kept.', 'octopus-image-optimizer'),
					],
					'png_compression' => [
						'type' => 'choice',
						'label' => __('Compression', 'octopus-image-optimizer'),
						'options' => [
							'lossless' => __('Lossless', 'octopus-image-optimizer'),
							'lossy' => __('Lossy (pngquant)', 'octopus-image-optimizer'),
						],
						'help' => __('Lossy: often 60 to 80 % lighter, for graphics and screenshots. Needs pngquant.', 'octopus-image-optimizer'),
					],
					'png_level' => [
						'type' => 'select',
						'label' => __('Optimisation level', 'octopus-image-optimizer'),
						'options' => self::levels(),
						/* translators: 1: the minimum, 2: the maximum, 3: the default. */
						'help' => self::bounds(__('From o%1$d (fastest) to o%2$d (smallest files), o%3$d by default.', 'octopus-image-optimizer'), 'png_level'),
					],
					'png_lossy_quality' => [
						'type' => 'number',
						'label' => __('Lossy quality', 'octopus-image-optimizer'),
						'when' => ['png_compression', 'lossy'],
						/* translators: 1: the minimum, 2: the maximum, 3: the default. */
						'help' => self::bounds(__('The quality pngquant aims at, %3$d by default.', 'octopus-image-optimizer'), 'png_lossy_quality'),
					],
				],
			],
			'webp' => [
				/* translators: %s: GD or Imagick. */
				'recommendation' => __('%s makes the WebP files for now, in the PHP process. cwebp runs apart from PHP, and makes the PNG graphics and screenshots much lighter.', 'octopus-image-optimizer'),
				'fields' => [
					'webp_enable' => [
						'type' => 'checkbox',
						'label' => __('Make WebP versions', 'octopus-image-optimizer'),
						'help' => __('Makes a WebP version of each image and thumbnail (<code>photo.jpg.webp</code>), lighter than the JPG or PNG.', 'octopus-image-optimizer'),
					],
					'webp_quality' => [
						'type' => 'number',
						'label' => __('Quality', 'octopus-image-optimizer'),
						/* translators: 1: the minimum, 2: the maximum, 3: the default. */
						'help' => self::bounds(__('From %1$d to %2$d, %3$d by default.', 'octopus-image-optimizer'), 'webp_quality'),
					],
				],
			],
			'avif' => [
				/* translators: %s: GD or Imagick. */
				'recommendation' => __('%s makes the AVIF files for now, in the PHP process. avifenc runs apart from PHP, on several threads, and keeps the colours of the PNG images sharper.', 'octopus-image-optimizer'),
				'fields' => [
					'avif_enable' => [
						'type' => 'checkbox',
						'label' => __('Make AVIF versions', 'octopus-image-optimizer'),
						'help' => __('Makes an AVIF version of each image and thumbnail (<code>photo.jpg.avif</code>), often the lightest. Kept only when lighter than the WebP and the JPG or PNG.', 'octopus-image-optimizer'),
					],
					'avif_quality' => [
						'type' => 'number',
						'label' => __('Quality', 'octopus-image-optimizer'),
						/* translators: 1: the minimum, 2: the maximum, 3: the default. */
						'help' => self::bounds(__('From %1$d to %2$d (lossless), %3$d by default.', 'octopus-image-optimizer'), 'avif_quality'),
					],
					'avif_speed' => [
						'type' => 'number',
						'label' => __('Speed', 'octopus-image-optimizer'),
						/* translators: 1: the minimum, 2: the maximum, 3: the default. */
						'help' => self::bounds(__('From %1$d to %2$d (fastest, heavier files), %3$d by default.', 'octopus-image-optimizer'), 'avif_speed'),
					],
				],
			],
			'general' => [
				'title' => __('Uploads', 'octopus-image-optimizer'),
				'description' => __('What happens to the images added to the media library.', 'octopus-image-optimizer'),
				'fields' => [
					'optimise_uploads' => [
						'type' => 'checkbox',
						'label' => __('Optimise new uploads', 'octopus-image-optimizer'),
						'help' => __('Each image added to the media library is optimised right away. Unticked, they wait in the Optimise tab, as new images.', 'octopus-image-optimizer'),
					],
					'max_size' => [
						'type' => 'number',
						'label' => __('Maximum image size', 'octopus-image-optimizer'),
						'unit' => 'px',
						/* translators: 1: the minimum, 2: the maximum, 3: the default. */
						'help' => self::bounds(__('WordPress scales the bigger images down on upload, and keeps them as their original. Longest side, %3$d by default. <strong>0 means no limit: huge images can exhaust the server.</strong>', 'octopus-image-optimizer'), 'max_size'),
					],
				],
			],
			'display' => [
				'title' => __('Serve', 'octopus-image-optimizer'),
				'description' => __('Each browser gets the lightest version it reads: AVIF, then WebP, or the JPG or PNG for older ones. Choose one way, or both.', 'octopus-image-optimizer'),
				'fields' => [
					'serve_picture' => [
						'type' => 'checkbox',
						'label' => __('In the HTML, with <picture>', 'octopus-image-optimizer'),
						'note' => __('Wraps the images of the content in a <code>&lt;picture&gt;</code>. Untick it if the layout breaks.', 'octopus-image-optimizer'),
						'help' => __('Images of the post content, featured images and galleries are wrapped in a <code>&lt;picture&gt;</code> tag offering the AVIF and WebP versions. Works on every server. <strong>Untick this if your theme layout breaks around images.</strong>', 'octopus-image-optimizer'),
					],
					'serve_rewrite' => [
						'type' => 'checkbox',
						'label' => __('From the server, with rewrite rules', 'octopus-image-optimizer'),
						'note' => __('Every image, CSS backgrounds included, the HTML unchanged.', 'octopus-image-optimizer'),
						'help' => __('The server answers <code>photo.jpg</code> with <code>photo.jpg.avif</code> or <code>.webp</code> when the browser reads them. The HTML does not change, and every image is served, CSS backgrounds and custom themes included. A CDN must vary its cache on the <code>Accept</code> header.', 'octopus-image-optimizer'),
					],
				],
			],
		];
	}

	/**
	 * Returns the help of a number setting with its
	 * bounds and its default, from Settings::FIELDS.
	 *
	 * @param string $help With %1$d the minimum, %2$d the maximum, %3$d the default.
	 * @param string $name
	 * @return string
	 * @since 1.0.0
	 */
	private static function bounds($help, $name)
	{
		$setting = Settings::FIELDS[$name];
		return sprintf($help, $setting['min'], $setting['max'], $setting['default']);
	}

	/**
	 * Returns the optimisation levels of the PNG images,
	 * o0 to o7, by value.
	 *
	 * @return string[]
	 * @since 1.0.0
	 */
	private static function levels()
	{
		$levels = [];
		foreach (range(Settings::FIELDS['png_level']['min'], Settings::FIELDS['png_level']['max']) as $level) {
			$levels[(string)$level] = 'o' . $level;
		}
		return $levels;
	}
}
