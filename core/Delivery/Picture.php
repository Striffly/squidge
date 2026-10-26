<?php

/**
 * Picture
 *
 * Picture wraps the HTML of an image from the
 * uploads folder in a <picture> element with its
 * AVIF and WebP versions (image.jpg.avif and
 * image.jpg.webp). Only files that exist are used,
 * the JPG or PNG is the fallback.
 *
 * @package     OIO
 * @author      Clément Gayot
 * @category    Class
 * @repo        https://github.com/Striffly/octopus-image-optimizer
 *
 */

namespace Octopus\Delivery;

if (!defined('ABSPATH')) {
	exit; // Exit if accessed directly
}

class Picture
{
	/**
	 * Attribute added to the rewritten <img>, so an
	 * image is never rewritten twice.
	 *
	 * @var string
	 */
	const MARKER = 'data-octopus';

	/**
	 * Modern formats, best first, as offered to the
	 * browser in <source> elements.
	 *
	 * @var array
	 */
	const FORMATS = [
		'.avif' => 'image/avif',
		'.webp' => 'image/webp',
	];

	/**
	 * Wraps the first <img> of the HTML in a <picture>
	 * with AVIF and WebP sources.
	 *
	 * @param string $html
	 * @param string|null $picture_class Class of the <picture> element, which is then always added.
	 * @return string
	 * @since 1.0.0
	 */
	public static function render($html, $picture_class = null)
	{
		if (strpos($html, self::MARKER) !== false || !preg_match('/<img\s[^>]*>/i', $html, $match)) {
			return $html;
		}
		$img = $match[0];
		$src = self::attr($img, 'src');
		if (!$src || !self::path($src)) {
			return $html;
		}
		$srcset = self::attr($img, 'srcset');

		$new_img = preg_replace('/^<img\s/i', '<img ' . self::MARKER . ' ', $img);

		$sources = '';
		$sizes = self::attr($img, 'sizes');
		foreach (self::sources($srcset ?: $src) as $type => $format_srcset) {
			$sources .= sprintf('<source type="%s" srcset="%s"%s>', $type, esc_attr($format_srcset), $sizes ? sprintf(' sizes="%s"', esc_attr($sizes)) : '');
		}

		if ($sources !== '' || $picture_class !== null) {
			$class = trim('octopus-picture ' . $picture_class);
			$new_img = sprintf('<picture class="%s">%s%s</picture>', esc_attr($class), $sources, $new_img);
		}

		return str_replace($img, $new_img, $html);
	}

	/**
	 * Returns the srcset of each modern format of a
	 * srcset or an image URL, keyed by MIME type, best
	 * first: only the formats with at least one file,
	 * empty for an image out of the uploads folder.
	 *
	 * @param string $srcset
	 * @return array
	 * @since 1.0.0
	 */
	public static function sources($srcset)
	{
		$candidates = self::parse_srcset((string)$srcset);
		$sources = [];
		foreach (self::FORMATS as $extension => $type) {
			$format_srcset = self::build_srcset($candidates, $extension);
			if ($format_srcset !== '') {
				$sources[$type] = $format_srcset;
			}
		}
		return $sources;
	}

	/**
	 * Returns the URL of the AVIF or WebP version of an
	 * image URL, if its file exists.
	 *
	 * @param string $url
	 * @param string $extension
	 * @return string|null
	 * @since 1.0.0
	 */
	private static function variant($url, $extension)
	{
		$variant = strtok($url, '?') . $extension;
		$path = self::path($variant);
		return $path && file_exists($path) ? $variant : null;
	}

	/**
	 * Returns the srcset of a format from the srcset
	 * candidates of the image, empty if no candidate has
	 * a file in this format. A candidate without one
	 * (e.g. deleted as heavier than the JPG or PNG)
	 * keeps its JPG or PNG: without it, the browser
	 * would pick a smaller size of this format.
	 *
	 * @param array $candidates
	 * @param string $extension
	 * @return string
	 * @since 1.0.0
	 */
	private static function build_srcset($candidates, $extension)
	{
		$parts = [];
		$found = false;
		foreach ($candidates as $candidate) {
			$url = self::variant($candidate[0], $extension);
			$found = $found || $url;
			$parts[] = trim(($url ?: $candidate[0]) . ' ' . $candidate[1]);
		}
		return $found ? implode(', ', $parts) : '';
	}

	/**
	 * Splits a srcset into [url, descriptor] candidates.
	 *
	 * @param string $srcset
	 * @return array
	 * @since 1.0.0
	 */
	private static function parse_srcset($srcset)
	{
		$candidates = [];
		foreach (explode(',', $srcset) as $candidate) {
			$parts = preg_split('/\s+/', trim($candidate), 2);
			if (!empty($parts[0])) {
				$candidates[] = [$parts[0], isset($parts[1]) ? $parts[1] : ''];
			}
		}
		return $candidates;
	}

	/**
	 * Returns the file path of a URL of the uploads
	 * folder, or null for any other URL.
	 *
	 * @param string $url
	 * @return string|null
	 * @since 1.0.0
	 */
	private static function path($url)
	{
		$uploads = wp_get_upload_dir();
		$base = preg_replace('#^https?:#i', '', $uploads['baseurl']);
		$url = preg_replace('#^https?:#i', '', strtok(html_entity_decode($url), '?'));
		if (strpos($url, $base . '/') !== 0 || preg_match('#(^|/)\.\.(/|$)#', $url)) {
			return null;
		}
		return $uploads['basedir'] . substr($url, strlen($base));
	}

	/**
	 * Returns the value of an attribute of a tag.
	 *
	 * @param string $tag
	 * @param string $name
	 * @return string
	 * @since 1.0.0
	 */
	private static function attr($tag, $name)
	{
		return preg_match('/\s' . $name . '\s*=\s*(["\'])(.*?)\1/i', $tag, $match) ? $match[2] : '';
	}
}
