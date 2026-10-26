<?php

/**
 * LibrariesTest
 *
 * Finding the libraries, their failures, and the
 * image editor of WordPress in their place.
 *
 * @package     OIO
 * @author      Clément Gayot
 * @repo        https://github.com/Striffly/octopus-image-optimizer
 *
 */

namespace Octopus\Tests\Integration;

use Octopus\Encoders\AVIF;
use Octopus\Encoders\JPG;
use Octopus\Encoders\PNG;
use Octopus\Encoders\WebP;
use Octopus\Log\Errors;
use Octopus\Media\Files;
use Octopus\Media\Status;
use Octopus\Tests\TestCase;

class LibrariesTest extends TestCase
{
	public function test_the_libraries_are_found_with_an_empty_path()
	{
		$this->require_libraries('cwebp');
		// PHP-FPM often passes no PATH.
		$path = getenv('PATH');
		putenv('PATH=');
		self::forget_libraries();
		try {
			$this->assertTrue(WebP::installed());
		} finally {
			putenv('PATH=' . $path);
		}
	}

	public function test_the_versions_of_the_libraries_are_read()
	{
		$services = [WebP::class => 'cwebp', AVIF::class => 'avifenc', JPG::class => 'jpegoptim', PNG::class => PNG::library()];
		foreach ($services as $service => $library) {
			$this->require_libraries($library);
			$this->assertMatchesRegularExpression('/^\d+(\.\d+)+$/', $service::version_number(), $library);
		}
	}

	public function test_a_library_set_by_hand_is_run()
	{
		$this->require_libraries('cwebp');
		$cwebp = self::program('cwebp');
		$copy = dirname($this->uploads) . '/bin/my-cwebp';
		wp_mkdir_p(dirname($copy));
		copy($cwebp, $copy);
		chmod($copy, 0755);
		self::hide_libraries('cwebp');
		add_filter('octopus_binary_path', function ($path, $name) use ($copy) {
			return $name === 'cwebp' ? $copy : $path;
		}, 20, 2);
		self::forget_libraries();

		$this->assertSame('cwebp', WebP::engine());
		$id = $this->upload('photo.jpg');
		$this->assertFileExists(get_attached_file($id) . '.webp');
	}

	public function test_a_failing_library_records_an_error()
	{
		$this->require_libraries('jpegoptim');
		self::fake_library('cwebp', "echo 'Saving file...'; echo 'Error! Cannot encode picture as WebP' >&2; exit 1");

		$id = $this->upload('photo.jpg');

		$this->assertSame(Status::ERROR, Status::of($id));
		$this->assertTrue(Errors::has($id, 'cwebp'));
		$this->assertStringContainsString('Error! Cannot encode picture as WebP', wp_json_encode(Errors::for_attachment($id)));
		$this->assertSame('', get_post_meta($id, WebP::meta_key(), true), 'Done again next time.');
		// The other formats go on.
		$this->assertSame('quality:80', get_post_meta($id, JPG::meta_key(), true));
	}

	public function test_an_image_the_library_fails_to_compress_gets_its_original_back()
	{
		self::fake_library('jpegoptim', 'exit 1');

		$id = $this->upload('photo.jpg');
		$main = get_attached_file($id);

		$this->assertTrue(Errors::has($id, 'jpegoptim'));
		$this->assertFileEquals(self::fixture('photo.jpg'), $main);
		$this->assertFileDoesNotExist(Files::backup_path($main), 'Retried next time.');
	}

	public function test_the_original_is_kept_when_it_cannot_be_put_back()
	{
		if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
			$this->markTestSkipped('root writes read-only files.');
		}
		// The library fails and leaves the file unwritable, as a full disk would.
		self::fake_library('jpegoptim', 'for file; do :; done; echo broken > "$file"; chmod 444 "$file"; exit 1');

		$id = $this->upload('photo.jpg');
		$main = get_attached_file($id);

		$this->assertFileEquals(self::fixture('photo.jpg'), Files::backup_path($main));
		$this->assertStringContainsString('cannot put the original back', wp_json_encode(Errors::for_attachment($id)));
		chmod($main, 0644);
	}

	public function test_the_webp_files_are_made_by_the_image_editor_without_cwebp()
	{
		$this->require_editor('image/webp');
		self::hide_libraries('cwebp');

		$this->assertContains(WebP::engine(), ['GD', 'Imagick']);
		$id = $this->upload('photo.jpg');
		$this->assert_versions($id, '.webp', 'RIFF', 0);
		$this->assertSame('quality:80', get_post_meta($id, WebP::meta_key(), true));
	}

	public function test_the_avif_files_are_made_by_the_image_editor_without_avifenc()
	{
		$this->require_editor('image/avif');
		self::hide_libraries('avifenc');
		self::set('octopus_avif_quality', 30);

		$this->assertContains(AVIF::engine(), ['GD', 'Imagick']);
		$id = $this->upload('photo.jpg');
		$this->assert_versions($id, '.avif', 'ftypavif', 4);
	}

	public function test_without_library_the_images_are_not_compressed()
	{
		self::hide_libraries('jpegoptim');

		$this->assertFalse(JPG::available());
		$id = $this->upload('photo.jpg');
		$this->assertFileEquals(self::fixture('photo.jpg'), get_attached_file($id));
		$this->assertFileDoesNotExist(Files::backup_path(get_attached_file($id)));
		$this->assertSame('', get_post_meta($id, JPG::meta_key(), true));
	}

	/**
	 * Replaces a library with a shell script, run with
	 * the arguments of the library.
	 *
	 * @param string $name
	 * @param string $script
	 */
	private function fake_library($name, $script)
	{
		$fake = dirname($this->uploads) . '/bin/' . $name;
		wp_mkdir_p(dirname($fake));
		file_put_contents($fake, "#!/bin/sh\n" . $script . "\n");
		chmod($fake, 0755);
		add_filter('octopus_binary_path', function ($path, $library) use ($name, $fake) {
			return $library === $name ? $fake : $path;
		}, 10, 2);
		self::forget_libraries();
	}

	/**
	 * Returns the path of a program from the PATH.
	 *
	 * @param string $name
	 * @return string
	 */
	private static function program($name)
	{
		foreach (explode(PATH_SEPARATOR, (string)getenv('PATH')) as $dir) {
			if (is_executable($dir . '/' . $name)) {
				return $dir . '/' . $name;
			}
		}
		return '/usr/bin/' . $name;
	}
}
