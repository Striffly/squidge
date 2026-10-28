<?php

/**
 * OrientationTest
 *
 * The photos stored sideways, which the browsers turn
 * from the orientation of their Exif data: their WebP
 * and AVIF versions are the right way up, even where
 * WordPress did not turn them.
 *
 * @package     OIO
 * @author      Clément Gayot
 * @repo        https://github.com/Striffly/octopus-image-optimizer
 *
 */

namespace Octopus\Tests\Integration;

use Octopus\Engines\ImageHeader;
use Octopus\Media\Files;
use Octopus\Tests\TestCase;

class OrientationTest extends TestCase
{
	/**
	 * The size of rotated.jpg as shown: stored 320 x
	 * 214, with the orientation 6 (turn 90° clockwise),
	 * a camera and a GPS position.
	 */
	const SHOWN = [214, 320];

	/**
	 * Needs the libraries. The AVIF files are made at a
	 * low quality, so that they are lighter than the
	 * WebP files and are kept, see OptimiseTest.
	 */
	public function set_up()
	{
		parent::set_up();
		$this->require_libraries('cwebp', 'avifenc', 'jpegoptim');
		self::set('avif_quality', 30);
	}

	public function test_the_orientation_is_read_from_the_exif_data()
	{
		$this->assertSame(6, ImageHeader::orientation(self::fixture('rotated.jpg')));
		$this->assertSame(1, ImageHeader::orientation(self::fixture('photo.jpg')));
		$this->assertSame(1, ImageHeader::orientation(self::fixture('p3.png')));
		$this->assertSame(1, ImageHeader::orientation(self::fixture('damaged.jpg')));
	}

	public function test_a_photo_wordpress_turned_stays_the_right_way_up()
	{
		if (!is_callable('exif_read_data')) {
			$this->markTestSkipped('WordPress needs the exif extension to turn the images.');
		}

		$main = get_attached_file($this->upload('rotated.jpg'));

		$this->assertStringEndsWith('rotated-rotated.jpg', $main);
		$this->assertSame(1, ImageHeader::orientation($main));
		$this->assert_shown_size($main);
	}

	public function test_a_photo_wordpress_left_sideways_keeps_its_orientation_and_gets_turned_versions()
	{
		// As an upload from before WordPress 5.3, or PHP without its exif extension.
		add_filter('wp_image_maybe_exif_rotate', '__return_false');

		$main = get_attached_file($this->upload('rotated.jpg'));

		$this->assertSame(6, ImageHeader::orientation($main), 'The browsers still turn the JPG.');
		$this->assertStringNotContainsString('OIO Test Camera', file_get_contents($main), 'The rest of the Exif data goes.');
		if (is_callable('exif_read_data')) {
			$exif = exif_read_data($main);
			$this->assertSame(6, $exif['Orientation']);
			$this->assertArrayNotHasKey('GPSLatitude', $exif);
		}
		$this->assertFileExists(Files::backup_path($main), 'The JPG is compressed.');
		$this->assert_shown_size($main);
		$this->assertSame([], glob(Files::temp_dir() . '/octopus-*'), 'The turned copy is removed.');
	}

	public function test_the_image_editor_turns_a_sideways_photo()
	{
		$this->require_editor('image/webp');
		self::hide_libraries('cwebp');
		add_filter('wp_image_maybe_exif_rotate', '__return_false');

		$main = get_attached_file($this->upload('rotated.jpg'));

		$this->assertSame(self::SHOWN, array_slice(getimagesize(Files::variant($main, '.webp')), 0, 2));
	}

	/**
	 * Checks that the WebP and AVIF versions of a file
	 * have the size of the photo as shown.
	 *
	 * @param string $file
	 */
	private function assert_shown_size($file)
	{
		foreach (['.webp', '.avif'] as $extension) {
			$version = Files::variant($file, $extension);
			$this->assertFileExists($version);
			$this->assertSame(self::SHOWN, array_slice(getimagesize($version), 0, 2), $version);
		}
	}
}
