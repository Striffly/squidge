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

use Octopus\Encoders\Formats;
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
			// webp_enable is unticked: the form sends 0.
			'webp_enable' => '0',
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

	public function test_saving_a_few_settings_keeps_the_others()
	{
		self::set('webp_quality', 60);

		update_option(Settings::OPTION, ['avif_quality' => '50']);

		$this->assertSame(50, Settings::get('avif_quality'));
		$this->assertSame(60, Settings::get('webp_quality'));
		$this->assertTrue(Settings::get('jpg_enable'), 'A box not sent keeps its value.');
	}

	public function test_the_png_level_and_lossy_quality_reach_pngquant_and_oxipng()
	{
		self::set('png_level', 4);
		self::set('png_lossy_quality', 65);
		self::set('png_compression', 'lossy');

		$args = Formats::settings()['png'];
		$this->assertSame('level:4', PNG::signature(['lossy' => false] + $args));
		if (PNG::installed('pngquant')) {
			$this->assertSame('level:4,lossy:65', PNG::signature($args));
		}
		$this->assertSame('level:3', PNG::signature(['optimization' => 'o3']), 'WP-CLI sets it.');
	}
}
