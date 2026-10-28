<?php

/**
 * ImageHeader
 *
 * Reads what the header of a JPG or PNG file tells
 * about its pixels, without decoding them: its colour
 * profile and its orientation, which it writes back
 * alone in a stripped JPG.
 *
 * @package     OIO
 * @author      Clément Gayot
 * @category    Class
 * @repo        https://github.com/Striffly/octopus-image-optimizer
 *
 */

namespace Octopus\Engines;

if (!defined('ABSPATH')) {
	exit; // Exit if accessed directly
}

class ImageHeader
{
	/**
	 * SRGB_PRIMARIES are the red, green and blue of
	 * sRGB, as the ICC profiles give them (XYZ, adapted
	 * to D50), by tag.
	 */
	const SRGB_PRIMARIES = [
		'rXYZ' => [0.4361, 0.2225, 0.0139],
		'gXYZ' => [0.3851, 0.7169, 0.0971],
		'bXYZ' => [0.1431, 0.0606, 0.7141],
	];

	/**
	 * PRIMARY_TOLERANCE is how far the primaries of a
	 * profile can be from SRGB_PRIMARIES: the sRGB
	 * profiles round them differently, Display P3 is
	 * 0.08 away.
	 */
	const PRIMARY_TOLERANCE = 0.002;

	/**
	 * CURVE_TOLERANCE is how far the tone curve of a
	 * profile can be from the one of sRGB, in linear
	 * light: a gamma 2.2 is 0.0036 away.
	 */
	const CURVE_TOLERANCE = 0.001;

	/**
	 * The values a tone curve is compared at, see
	 * is_srgb().
	 */
	const CURVE_SAMPLES = [0.02, 0.1, 0.25, 0.5, 0.75, 0.9];

	/**
	 * Returns the colour space of the colour profile
	 * of an image (RGB, GRAY, CMYK), or null if it has
	 * none, or an sRGB one: the browsers show an image
	 * without profile as sRGB, it can go. Another one,
	 * e.g. the Display P3 of a phone or the Adobe RGB
	 * of a camera, is needed for the colours.
	 *
	 * @param string $path
	 * @return string|null
	 * @since 1.0.0
	 */
	public static function profile($path)
	{
		$icc = self::icc($path);
		if ($icc === null || strlen($icc) < 132) {
			return null;
		}
		$space = rtrim(substr($icc, 16, 4));
		return $space === 'RGB' && self::is_srgb($icc) ? null : $space;
	}

	/**
	 * Returns the orientation of a JPG file, from its
	 * Exif data: 1 to 8, as WordPress reads it (see
	 * WP_Image_Editor::maybe_exif_rotate()), 1 without
	 * one, the pixels the right way up. Read without
	 * the exif extension of PHP, which some servers
	 * lack.
	 *
	 * @param string $path
	 * @return int
	 * @since 1.0.0
	 */
	public static function orientation($path)
	{
		if (self::type($path) !== 'jpg') {
			return 1;
		}
		foreach (self::jpeg_segments($path, 0xE1) as $segment) {
			if (strncmp($segment, "Exif\0\0", 6) !== 0) {
				continue;
			}
			// A TIFF structure, little (II) or big endian (MM): the first directory
			// (IFD0) holds the orientation, tag 0x0112.
			$tiff = substr($segment, 6);
			$order = substr($tiff, 0, 2);
			if (strlen($tiff) < 8 || !in_array($order, ['II', 'MM'], true)) {
				return 1;
			}
			list($short, $long) = $order === 'II' ? ['v', 'V'] : ['n', 'N'];
			$ifd = unpack($long, substr($tiff, 4, 4))[1];
			$count = $ifd + 2 <= strlen($tiff) ? unpack($short, substr($tiff, $ifd, 2))[1] : 0;
			for ($i = 0; $i < $count && $ifd + 14 + 12 * $i <= strlen($tiff); $i++) {
				$entry = substr($tiff, $ifd + 2 + 12 * $i, 12);
				if (unpack($short, substr($entry, 0, 2))[1] === 0x0112) {
					$orientation = unpack($short, substr($entry, 8, 2))[1];
					return $orientation >= 1 && $orientation <= 8 ? $orientation : 1;
				}
			}
			return 1;
		}
		return 1;
	}

	/**
	 * Returns the bytes of a JPG file without Exif data
	 * with an Exif segment holding its orientation only
	 * (see orientation()): the one tag the browsers need
	 * to turn it, without the GPS position and the
	 * camera of the whole Exif data. After the JFIF
	 * segment, which comes first, if any.
	 *
	 * @param string $jpeg
	 * @param int $orientation 2 to 8.
	 * @return string
	 * @since 1.0.0
	 */
	public static function with_orientation($jpeg, $orientation)
	{
		// A big endian TIFF structure: one directory (IFD0) of two entries, each
		// one SHORT padded to 4 bytes, then no other. The orientation (tag
		// 0x0112), and the position of the chroma (0x0213, centred), which the
		// Exif standard requires.
		$entries = pack('nnNnn', 0x0112, 3, 1, $orientation, 0) . pack('nnNnn', 0x0213, 3, 1, 1, 0);
		$tiff = 'MM' . pack('nN', 42, 8) . pack('n', 2) . $entries . pack('N', 0);
		$exif = "Exif\0\0" . $tiff;
		$offset = 2;
		if (substr($jpeg, 2, 2) === "\xFF\xE0" && strlen($jpeg) >= 6) {
			$offset += 2 + unpack('n', substr($jpeg, 4, 2))[1];
		}
		return substr($jpeg, 0, $offset) . "\xFF\xE1" . pack('n', strlen($exif) + 2) . $exif . substr($jpeg, $offset);
	}

	/**
	 * Returns the type of an image file from its first
	 * bytes: jpg, png, or null for another one.
	 *
	 * @param string $path
	 * @return string|null
	 * @since 1.0.0
	 */
	private static function type($path)
	{
		// Read first: the file may have just been written, e.g. copied from its original.
		clearstatcache(true, $path);
		$signature = (string)self::read($path, 0, 8);
		if ($signature === "\x89PNG\r\n\x1a\n") {
			return 'png';
		}
		return strncmp($signature, "\xFF\xD8", 2) === 0 ? 'jpg' : null;
	}

	/**
	 * Returns the ICC profile of a JPG or PNG file, null
	 * if it has none.
	 *
	 * @param string $path
	 * @return string|null
	 * @since 1.0.0
	 */
	private static function icc($path)
	{
		$type = self::type($path);
		if ($type === 'png') {
			$chunk = self::png_chunk($path, 'iCCP');
			// The name of the profile, then the compression method, then the profile.
			$start = $chunk === null ? false : strpos($chunk, "\0");
			$icc = $start === false ? false : @gzuncompress(substr($chunk, $start + 2));
			return $icc === false ? null : $icc;
		}
		if ($type !== 'jpg') {
			return null;
		}
		// A profile can be cut in several APP2 segments, numbered.
		$parts = [];
		foreach (self::jpeg_segments($path, 0xE2) as $segment) {
			if (strncmp($segment, "ICC_PROFILE\0", 12) === 0 && strlen($segment) > 14) {
				$parts[ord($segment[12])] = substr($segment, 14);
			}
		}
		ksort($parts);
		return $parts ? implode('', $parts) : null;
	}

	/**
	 * Determines if an RGB profile is sRGB: its
	 * primaries and its tone curves are those of sRGB,
	 * whatever its name or version. A profile made of
	 * tables (LUT) is not compared: kept.
	 *
	 * @param string $icc
	 * @return bool
	 * @since 1.0.0
	 */
	private static function is_srgb($icc)
	{
		$tags = self::icc_tags($icc);
		foreach (self::SRGB_PRIMARIES as $tag => $expected) {
			$xyz = isset($tags[$tag]) && substr($tags[$tag], 0, 4) === 'XYZ ' && strlen($tags[$tag]) >= 20 ? array_map([self::class, 's15fixed16'], str_split(substr($tags[$tag], 8, 12), 4)) : null;
			if ($xyz === null) {
				return false;
			}
			foreach ($expected as $index => $value) {
				if (abs($xyz[$index] - $value) > self::PRIMARY_TOLERANCE) {
					return false;
				}
			}
		}
		foreach (['rTRC', 'gTRC', 'bTRC'] as $tag) {
			foreach (self::CURVE_SAMPLES as $x) {
				$y = isset($tags[$tag]) ? self::curve($tags[$tag], $x) : null;
				$srgb = $x <= 0.04045 ? $x / 12.92 : pow(($x + 0.055) / 1.055, 2.4);
				if ($y === null || abs($y - $srgb) > self::CURVE_TOLERANCE) {
					return false;
				}
			}
		}
		return true;
	}

	/**
	 * Returns the tags of an ICC profile, by
	 * signature.
	 *
	 * @param string $icc
	 * @return string[]
	 * @since 1.0.0
	 */
	private static function icc_tags($icc)
	{
		$tags = [];
		$count = unpack('N', substr($icc, 128, 4))[1];
		for ($i = 0; $i < $count && 144 + 12 * $i <= strlen($icc); $i++) {
			$entry = unpack('a4signature/Noffset/Nsize', substr($icc, 132 + 12 * $i, 12));
			$tags[$entry['signature']] = (string)substr($icc, $entry['offset'], $entry['size']);
		}
		return $tags;
	}

	/**
	 * Returns the value of a tone curve of an ICC
	 * profile (curv or para type) at x, from 0 to 1,
	 * or null if it cannot be read.
	 *
	 * @param string $tag
	 * @param float $x
	 * @return float|null
	 * @since 1.0.0
	 */
	private static function curve($tag, $x)
	{
		$type = substr($tag, 0, 4);
		if ($type === 'curv' && strlen($tag) >= 12) {
			$count = unpack('N', substr($tag, 8, 4))[1];
			if ($count === 0) {
				return $x;
			}
			if (strlen($tag) < 12 + 2 * $count) {
				return null;
			}
			$table = array_values(unpack('n' . $count, substr($tag, 12, 2 * $count)));
			// One value: a gamma (u8Fixed8). More: a table, read between its points.
			if ($count === 1) {
				return pow($x, $table[0] / 256);
			}
			$position = $x * ($count - 1);
			$index = min((int)$position, $count - 2);
			return ($table[$index] + ($table[$index + 1] - $table[$index]) * ($position - $index)) / 65535;
		}
		// The parametric functions of the ICC specification, by number of parameters.
		$counts = [1, 3, 4, 5, 7];
		$function = $type === 'para' && strlen($tag) >= 12 ? unpack('n', substr($tag, 8, 2))[1] : -1;
		if (!isset($counts[$function]) || strlen($tag) < 12 + 4 * $counts[$function]) {
			return null;
		}
		$p = array_pad(array_map([self::class, 's15fixed16'], str_split(substr($tag, 12, 4 * $counts[$function]), 4)), 7, 0.0);
		list($g, $a, $b, $c, $d, $e, $f) = $p;
		switch ($function) {
			case 0:
				return pow($x, $g);
			case 1:
				return $x >= -$b / $a ? pow($a * $x + $b, $g) : 0.0;
			case 2:
				return $x >= -$b / $a ? pow($a * $x + $b, $g) + $c : $c;
			case 3:
				return $x >= $d ? pow($a * $x + $b, $g) : $c * $x;
			default:
				return $x >= $d ? pow($a * $x + $b, $g) + $e : $c * $x + $f;
		}
	}

	/**
	 * Returns a signed 15.16 fixed point number of an
	 * ICC profile.
	 *
	 * @param string $bytes
	 * @return float
	 * @since 1.0.0
	 */
	private static function s15fixed16($bytes)
	{
		$value = unpack('N', $bytes)[1];
		return ($value >= 0x80000000 ? $value - 0x100000000 : $value) / 65536;
	}

	/**
	 * Returns the content of the segments of a type of a
	 * JPG file (e.g. 0xE2 for APP2), the metadata
	 * before the pixels.
	 *
	 * @param string $path
	 * @param int $type
	 * @return string[]
	 * @since 1.0.0
	 */
	private static function jpeg_segments($path, $type)
	{
		$segments = [];
		$offset = 2;
		while (($header = self::read($path, $offset, 4)) !== null && $header[0] === "\xFF") {
			$marker = ord($header[1]);
			// The pixels (start of scan) or the end of the image: no metadata after.
			if ($marker === 0xDA || $marker === 0xD9) {
				break;
			}
			$length = unpack('n', substr($header, 2, 2))[1];
			if ($length < 2) {
				break;
			}
			if ($marker === $type) {
				$segments[] = (string)self::read($path, $offset + 4, $length - 2);
			}
			$offset += 2 + $length;
		}
		return $segments;
	}

	/**
	 * Returns the content of the first chunk of a type
	 * of a PNG file before its pixels, null if there is
	 * none.
	 *
	 * @param string $path
	 * @param string $type E.g. iCCP.
	 * @return string|null
	 * @since 1.0.0
	 */
	private static function png_chunk($path, $type)
	{
		$offset = 8;
		while (($header = self::read($path, $offset, 8)) !== null) {
			$length = unpack('N', substr($header, 0, 4))[1];
			$name = substr($header, 4, 4);
			if ($name === $type) {
				return self::read($path, $offset + 8, $length);
			}
			if ($name === 'IDAT' || $name === 'IEND') {
				return null;
			}
			// The length, the type, the content and its CRC.
			$offset += 12 + $length;
		}
		return null;
	}

	/**
	 * Returns bytes of a file, null if it is shorter.
	 *
	 * @param string $path
	 * @param int $offset
	 * @param int $length
	 * @return string|null
	 * @since 1.0.0
	 */
	private static function read($path, $offset, $length)
	{
		$size = @filesize($path);
		if ($size === false || $length < 0 || $offset + $length > $size) {
			return null;
		}
		if ($length === 0) {
			return '';
		}
		$bytes = file_get_contents($path, false, null, $offset, $length);
		return is_string($bytes) && strlen($bytes) === $length ? $bytes : null;
	}
}
