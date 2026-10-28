<?php

/**
 * Picture
 *
 * Picture wraps the HTML of an image from the
 * uploads folder in a <picture> element with its
 * AVIF and WebP versions (image.jpg.avif and
 * image.jpg.webp, in uploads/octopus/formats). Only
 * files that exist are used, the JPG or PNG is the
 * fallback.
 *
 * @package     OIO
 * @author      Clément Gayot
 * @category    Class
 * @repo        https://github.com/Striffly/octopus-image-optimizer
 *
 */

namespace Octopus\Delivery;

use Octopus\Encoders\Formats;
use Octopus\Media\Files;
use WP_HTML_Tag_Processor;

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
	 * The candidates of a srcset looked at most: each
	 * costs two lookups on the disk, on every view of
	 * the page. WordPress writes one by size of the
	 * image, a dozen at most; more, the image is left
	 * as it is.
	 *
	 * @var int
	 */
	const MAX_CANDIDATES = 100;

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
		// Read as the browser does: an attribute is never found inside another one.
		$tags = new WP_HTML_Tag_Processor($html);
		if (!$tags->next_tag('img') || $tags->get_attribute(self::MARKER) !== null) {
			return $html;
		}
		$src = self::attribute($tags, 'src');
		if ($src === '' || self::relative($src) === null) {
			return $html;
		}
		$srcset = self::attribute($tags, 'srcset');
		$sizes = self::attribute($tags, 'sizes');
		if (count(self::parse_srcset($srcset ?: $src)) > self::MAX_CANDIDATES) {
			return $html;
		}
		// Added right after the name of the tag, where it is found again.
		$tags->set_attribute(self::MARKER, true);
		$html = $tags->get_updated_html();

		$sources = '';
		foreach (self::sources($srcset ?: $src) as $type => $format_srcset) {
			$sources .= sprintf('<source type="%s" srcset="%s"%s>', $type, esc_attr($format_srcset), $sizes ? sprintf(' sizes="%s"', esc_attr($sizes)) : '');
		}
		$start = stripos($html, '<img ' . self::MARKER);
		// The tag, to its >: a > in a quoted attribute does not end it.
		if ($start === false || ($sources === '' && $picture_class === null) || !preg_match('/\G<img\b(?:[^>"\']|"[^"]*"|\'[^\']*\')*>/i', $html, $match, 0, $start)) {
			return $html;
		}
		$class = trim('octopus-picture ' . $picture_class);
		$picture = sprintf('<picture class="%s">%s%s</picture>', esc_attr($class), $sources, $match[0]);
		return substr_replace($html, $picture, $start, strlen($match[0]));
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
		if (count($candidates) > self::MAX_CANDIDATES) {
			return [];
		}
		$sources = [];
		// The lightest first, as offered to the browser in <source> elements.
		foreach (array_reverse(Formats::variants()) as $encoder) {
			$format_srcset = self::build_srcset($candidates, $encoder::extension());
			if ($format_srcset !== '') {
				$sources[$encoder::MIME] = $format_srcset;
			}
		}
		return $sources;
	}

	/**
	 * Returns the URL of the AVIF or WebP version of an
	 * image URL, if its file exists: in the folder of
	 * the versions, under the same address as the
	 * image (its scheme and host kept).
	 *
	 * @param string $url
	 * @param string $extension
	 * @return string|null
	 * @since 1.0.0
	 */
	private static function variant($url, $extension)
	{
		$relative = self::relative($url);
		if ($relative === null) {
			return null;
		}
		$path = Files::variant(wp_get_upload_dir()['basedir'] . $relative, $extension);
		if (!file_exists($path)) {
			return null;
		}
		$url = strtok(html_entity_decode($url), '?');
		return substr($url, 0, -strlen($relative)) . '/' . Files::DIR . '/' . Files::FORMATS_DIR . $relative . $extension;
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
	 * Returns the path of a URL of the uploads folder
	 * relative to it, with a / first, or null for any
	 * other URL, and for the files of OIO.
	 *
	 * @param string $url
	 * @return string|null
	 * @since 1.0.0
	 */
	private static function relative($url)
	{
		$base = preg_replace('#^https?:#i', '', wp_get_upload_dir()['baseurl']);
		$url = preg_replace('#^https?:#i', '', strtok(html_entity_decode($url), '?'));
		if (strpos($url, $base . '/') !== 0 || preg_match('#(^|/)\.\.(/|$)#', $url)) {
			return null;
		}
		$relative = substr($url, strlen($base));
		return strpos($relative, '/' . Files::DIR . '/') === 0 ? null : $relative;
	}

	/**
	 * Returns the value of an attribute of the current
	 * tag, empty if it has none or no value.
	 *
	 * @param WP_HTML_Tag_Processor $tags
	 * @param string $name
	 * @return string
	 * @since 1.0.0
	 */
	private static function attribute($tags, $name)
	{
		$value = $tags->get_attribute($name);
		return is_string($value) ? $value : '';
	}
}
