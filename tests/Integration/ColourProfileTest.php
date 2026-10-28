<?php

/**
 * ColourProfileTest
 *
 * The colour profiles of the images: kept in every
 * format when the colours need it (Display P3, Adobe
 * RGB), dropped when it is sRGB, the colours of an
 * image without one.
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

class ColourProfileTest extends TestCase
{
	/**
	 * The bytes starting a colour profile, by format.
	 */
	const PROFILE_MARKS = ['jpg' => "ICC_PROFILE\0", 'png' => 'iCCP', 'webp' => 'ICCP', 'avif' => 'colrprof'];

	/**
	 * Needs the libraries. The AVIF files are made at a
	 * low quality, so that they are lighter than the
	 * WebP files and are kept, see OptimiseTest.
	 */
	public function set_up()
	{
		parent::set_up();
		$this->require_libraries('cwebp', 'avifenc', 'jpegoptim', 'optipng');
		self::set('avif_quality', 30);
	}

	public function test_the_profiles_the_colours_need_are_told_from_srgb()
	{
		$this->assertSame('RGB', ImageHeader::profile(self::fixture('p3.jpg')));
		$this->assertSame('RGB', ImageHeader::profile(self::fixture('p3.png')));
		// sRGB IEC61966-2.1, as Photoshop and the cameras embed it.
		$this->assertTrue(self::has_profile(self::fixture('photo.jpg')));
		$this->assertNull(ImageHeader::profile(self::fixture('photo.jpg')));
		$this->assertNull(ImageHeader::profile(self::fixture('graphic.png')));
		$this->assertNull(ImageHeader::profile(self::fixture('damaged.jpg')));
	}

	public function test_a_display_p3_photo_keeps_its_profile_in_every_format()
	{
		$main = get_attached_file($this->upload('p3.jpg'));

		$this->assertTrue(self::has_profile($main));
		$this->assertTrue(self::has_profile(Files::variant($main, '.webp')));
		$this->assertTrue(self::has_profile(Files::variant($main, '.avif')));
	}

	public function test_an_srgb_profile_goes_from_every_format()
	{
		$main = get_attached_file($this->upload('photo.jpg'));

		$this->assertFalse(self::has_profile($main));
		$this->assertFalse(self::has_profile(Files::variant($main, '.webp')));
		$this->assertFalse(self::has_profile(Files::variant($main, '.avif')));
	}

	public function test_a_png_keeps_its_profile_with_optipng()
	{
		self::hide_libraries('oxipng');

		$main = get_attached_file($this->upload('p3.png'));

		$this->assertTrue(self::has_profile($main));
		$this->assertTrue(self::has_profile(Files::variant($main, '.webp')));
	}

	public function test_a_png_keeps_its_profile_with_oxipng()
	{
		$this->require_libraries('oxipng');

		$this->assertTrue(self::has_profile(get_attached_file($this->upload('p3.png'))));
	}

	/**
	 * Determines if a JPG, PNG, WebP or AVIF file
	 * embeds a colour profile.
	 *
	 * @param string $path
	 * @return bool
	 */
	private static function has_profile($path)
	{
		$extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
		$extension = $extension === 'jpeg' ? 'jpg' : $extension;
		return strpos(file_get_contents($path), self::PROFILE_MARKS[$extension]) !== false;
	}
}
