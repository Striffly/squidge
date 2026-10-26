<?php

/**
 * SettingsTest
 *
 * The settings of OIO, saved in one option by the
 * settings page.
 *
 * @package     OIO
 * @author      Clément Gayot
 * @repo        https://github.com/Striffly/octopus-image-optimizer
 *
 */

namespace Octopus\Tests\Integration;

use Octopus\Encoders\PNG;
use Octopus\Settings;
use Octopus\Tests\TestCase;

class SettingsTest extends TestCase
{
	public function test_the_defaults_until_saved()
	{
		delete_option(Settings::OPTION);

		$this->assertTrue(Settings::get('webp_enable'));
		$this->assertSame(70, Settings::get('avif_quality'));
		$this->assertSame('lossless', Settings::get('png_compression'));
		$this->assertNull(Settings::get('unknown'));
	}

	public function test_the_form_is_saved_cleaned()
	{
		update_option(Settings::OPTION, [
			// webp_enable is unticked: not sent.
			'jpg_enable' => '1',
			'jpg_quality' => '150',
			'avif_quality' => '',
			'png_compression' => 'lossy; DROP TABLE',
			'max_size' => '1200',
			'unknown' => 'kept out',
		]);

		$saved = get_option(Settings::OPTION);
		$this->assertSame(array_keys(Settings::FIELDS), array_keys($saved), 'Every setting, and nothing else.');
		$this->assertTrue(Settings::get('jpg_enable'));
		$this->assertFalse(Settings::get('webp_enable'));
		$this->assertSame(100, Settings::get('jpg_quality'), 'Within its bounds.');
		$this->assertSame(70, Settings::get('avif_quality'), 'An emptied field gets its default.');
		$this->assertSame('lossless', Settings::get('png_compression'), 'One of its values.');
		$this->assertSame(1200, Settings::get('max_size'));
	}

	public function test_the_png_level_and_lossy_quality_are_set_by_filters()
	{
		$this->assertSame('level:2', PNG::signature(['lossy' => false]));

		add_filter('octopus_png_level', function () {
			return 4;
		});
		add_filter('octopus_pngquant_quality', function () {
			return 65;
		});

		$this->assertSame('level:4', PNG::signature(['lossy' => false]));
		if (PNG::installed('pngquant')) {
			$this->assertSame('level:4,lossy:65', PNG::signature(['lossy' => true]));
		}
		$this->assertSame('level:3', PNG::signature(['lossy' => false, 'optimization' => 'o3']), 'WP-CLI sets it.');
	}
}
