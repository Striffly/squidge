<?php

/**
 * OptimiseTest
 *
 * Optimising an image on upload with the libraries,
 * and undoing it: the originals come back byte for
 * byte.
 *
 * @package     OIO
 * @author      Clément Gayot
 * @repo        https://github.com/Striffly/octopus-image-optimizer
 *
 */

namespace Octopus\Tests\Integration;

use Exception;
use Octopus\Encoders\AVIF;
use Octopus\Encoders\Formats;
use Octopus\Encoders\JPG;
use Octopus\Encoders\PNG;
use Octopus\Encoders\WebP;
use Octopus\Log\Errors;
use Octopus\Media\Cleaner;
use Octopus\Media\Files;
use Octopus\Media\Optimiser;
use Octopus\Media\Stats;
use Octopus\Media\Status;
use Octopus\Tests\TestCase;

class OptimiseTest extends TestCase
{
	/**
	 * Needs the libraries. The AVIF files are made at a
	 * low quality, so that they are lighter than the
	 * WebP files on the small fixtures and are kept.
	 */
	public function set_up()
	{
		parent::set_up();
		$this->require_libraries('cwebp', 'avifenc', 'jpegoptim', 'optipng');
		self::set('avif_quality', 30);
	}

	public function test_upload_makes_the_webp_and_avif_files_of_every_size()
	{
		$id = $this->upload('photo.jpg');

		$this->assertCount(3, self::files($id), 'The photo has its main file and 2 thumbnails.');
		$this->assert_versions($id, '.webp', 'RIFF', 0);
		$this->assert_versions($id, '.avif', 'ftypavif', 4);
		$this->assertSame(Status::OPTIMISED, Status::of($id));
		$this->assertSame('quality:80', get_post_meta($id, WebP::meta_key(), true));
		$this->assertSame('quality:30', get_post_meta($id, AVIF::meta_key(), true));
	}

	public function test_an_upload_waits_to_be_optimised_when_the_uploads_are_not()
	{
		self::set('optimise_uploads', false);

		$id = $this->upload('photo.jpg');

		$this->assertSame(Status::TO_OPTIMISE, Status::of($id));
		$this->assertFileDoesNotExist(Files::variant(get_attached_file($id), '.webp'));
		$this->assertFileDoesNotExist(Files::backup_path(get_attached_file($id)));
	}

	public function test_upload_compresses_the_jpg_files_and_keeps_their_originals()
	{
		$id = $this->upload('photo.jpg');

		$main = get_attached_file($id);
		$this->assertFileEquals(self::fixture('photo.jpg'), Files::backup_path($main));
		foreach (self::files($id) as $file) {
			$backup = Files::backup_path($file);
			$this->assertFileExists($backup);
			$this->assertLessThan(filesize($backup), filesize($file), $file);
			// jpegoptim --all-progressive: a progressive frame (SOF2).
			$this->assertStringContainsString("\xFF\xC2", file_get_contents($file), $file);
		}
	}

	public function test_the_folder_of_the_originals_is_denied_to_the_web()
	{
		$this->upload('photo.jpg');

		$dir = Files::backup_dir();
		$this->assertStringContainsString('Require all denied', file_get_contents($dir . '/.htaccess'));
		$this->assertFileExists($dir . '/index.php');
	}

	public function test_the_metadata_has_the_sizes_of_the_compressed_files()
	{
		$id = $this->upload('photo.jpg');

		clearstatcache();
		$metadata = wp_get_attachment_metadata($id);
		$main = get_attached_file($id);
		$this->assertSame(filesize($main), $metadata['filesize']);
		foreach ($metadata['sizes'] as $size) {
			$this->assertSame(filesize(dirname($main) . '/' . $size['file']), $size['filesize'], $size['file']);
		}
	}

	public function test_the_bytes_saved_are_measured()
	{
		$id = $this->upload('photo.jpg');

		// The browsers take the AVIF first, then the WebP, then the JPG.
		clearstatcache();
		$original = $compressed = $modern = $extra = 0;
		foreach (self::files($id) as $file) {
			$original += filesize(Files::backup_path($file));
			$compressed += filesize($file);
			$served = filesize($file);
			foreach (['.webp', '.avif'] as $extension) {
				if (file_exists(Files::variant($file, $extension))) {
					$served = filesize(Files::variant($file, $extension));
					$extra += $served;
				}
			}
			$modern += $served;
		}
		$this->assertSame(compact('original', 'compressed', 'modern', 'extra'), Stats::of($id));

		$this->upload('photo.jpg');
		$totals = Stats::totals(true);
		$this->assertSame(2, $totals['images']);
		$this->assertSame(2 * $original, $totals['original']);
	}

	public function test_restore_brings_the_originals_back_byte_for_byte()
	{
		$id = $this->upload('photo.jpg');
		$files = self::files($id);
		$originals = self::hashes(array_map([Files::class, 'backup_path'], $files));

		$this->assertTrue(Cleaner::restore($id));

		$this->assertSame(array_values($originals), array_values(self::hashes($files)));
		$this->assertFileEquals(self::fixture('photo.jpg'), get_attached_file($id));
		foreach ($files as $file) {
			$this->assertFileDoesNotExist(Files::variant($file, '.webp'));
			$this->assertFileDoesNotExist(Files::variant($file, '.avif'));
			$this->assertFileDoesNotExist(Files::backup_path($file));
		}
		$this->assertSame([], preg_grep('/^_octopus_/', array_keys(get_post_meta($id))));
		clearstatcache();
		$this->assertSame(filesize(self::fixture('photo.jpg')), wp_get_attachment_metadata($id)['filesize']);
		$this->assertSame(Status::TO_OPTIMISE, Status::of($id));
	}

	public function test_remove_formats_keeps_the_compressed_images()
	{
		$id = $this->upload('photo.jpg');
		$files = self::files($id);
		$compressed = self::hashes($files);

		$this->assertTrue(Cleaner::remove_formats($id));

		$this->assertSame($compressed, self::hashes($files));
		foreach ($files as $file) {
			$this->assertFileDoesNotExist(Files::variant($file, '.webp'));
			$this->assertFileDoesNotExist(Files::variant($file, '.avif'));
			$this->assertFileExists(Files::backup_path($file));
		}
		$this->assertSame('', get_post_meta($id, WebP::meta_key(), true));
		$this->assertSame('quality:80', get_post_meta($id, JPG::meta_key(), true));
		$this->assertSame(Status::TO_OPTIMISE, Status::of($id));
	}

	public function test_png_with_optipng()
	{
		self::hide_libraries('oxipng');
		$this->assertSame('optipng', PNG::library());

		$this->assert_png_compressed($this->upload('graphic.png'));
	}

	public function test_png_with_oxipng()
	{
		$this->require_libraries('oxipng');
		$this->assertSame('oxipng', PNG::library());

		$this->assert_png_compressed($this->upload('graphic.png'));
	}

	public function test_png_lossy_with_pngquant()
	{
		$this->require_libraries('pngquant');
		$lossless = filesize(get_attached_file($this->upload('graphic.png')));

		self::set('png_compression', 'lossy');
		$id = $this->upload('graphic.png');

		$this->assertSame('level:2,lossy:80', get_post_meta($id, PNG::meta_key(), true));
		clearstatcache();
		$this->assertLessThan($lossless, filesize(get_attached_file($id)));
		$this->assert_png_compressed($id);
	}

	public function test_the_files_heavier_than_the_image_are_deleted_and_count_as_done()
	{
		// 16 x 16 pixels: an AVIF file has more headers than pixels.
		$id = $this->upload('tiny.png');
		$file = get_attached_file($id);

		$this->assertFileDoesNotExist(Files::variant($file, '.avif'));
		$this->assertContains(Files::relative(Files::variant($file, '.avif')), Optimiser::heavier($id));
		$this->assertSame(Status::OPTIMISED, Status::of($id));

		// Done: not made again by the next optimisation.
		Optimiser::attachment($id, null, Formats::settings());
		$this->assertFileDoesNotExist(Files::variant($file, '.avif'));
	}

	public function test_an_avif_heavier_than_its_webp_is_deleted()
	{
		self::set('avif_quality', 100);
		$id = $this->upload('photo.jpg');
		$file = get_attached_file($id);

		$this->assertFileExists(Files::variant($file, '.webp'));
		$this->assertFileDoesNotExist(Files::variant($file, '.avif'));
		$this->assertContains(Files::relative(Files::variant($file, '.avif')), Optimiser::heavier($id));
	}

	public function test_an_image_wordpress_cannot_read_is_an_error()
	{
		$id = $this->upload('damaged.jpg');

		$this->assertSame(Status::ERROR, Status::of($id));
		$errors = Errors::for_attachment($id);
		$this->assertNotEmpty($errors);
		$this->assertStringContainsString('cannot read the image', wp_json_encode($errors));
	}

	public function test_new_settings_are_applied_from_the_originals()
	{
		$id = $this->upload('photo.jpg');
		$originals = self::hashes(array_map([Files::class, 'backup_path'], self::files($id)));
		$webp = filesize(Files::variant(get_attached_file($id), '.webp'));

		self::set('webp_quality', 40);
		$this->assertSame(Status::OUTDATED, Status::of($id));
		$this->assertTrue(Optimiser::update($id));

		$this->assertSame(Status::OPTIMISED, Status::of($id));
		$this->assertSame('quality:40', get_post_meta($id, WebP::meta_key(), true));
		clearstatcache();
		$this->assertLessThan($webp, filesize(Files::variant(get_attached_file($id), '.webp')));
		$this->assertSame($originals, self::hashes(array_map([Files::class, 'backup_path'], self::files($id))));
	}

	public function test_the_args_of_wp_cli_keep_within_the_bounds_of_the_settings()
	{
		$default = $this->upload_unoptimised('photo.jpg');
		Optimiser::attachment($default, null, ['jpg' => []]);
		$wrong = $this->upload_unoptimised('photo.jpg');
		Optimiser::attachment($wrong, null, ['jpg' => ['quality' => 'abc']]);

		// Compressed at the default quality, as its signature says, not at 0.
		$this->assertSame('quality:80', get_post_meta($wrong, JPG::meta_key(), true));
		$this->assertFileEquals(get_attached_file($default), get_attached_file($wrong));
		$this->assertSame('quality:100', JPG::signature(['quality' => 150]));
		$this->assertSame('level:7', PNG::signature(['optimization' => 9]));
	}

	public function test_a_failed_regeneration_leaves_the_uploads_optimised()
	{
		$id = $this->upload('photo.jpg');
		$fail = function () {
			throw new Exception('The thumbnails cannot be made.');
		};
		add_filter('intermediate_image_sizes_advanced', $fail);
		try {
			Optimiser::regenerate($id);
			$this->fail('The exception goes up.');
		} catch (Exception $e) {
			$this->assertSame('The thumbnails cannot be made.', $e->getMessage());
		}
		remove_filter('intermediate_image_sizes_advanced', $fail);

		$this->assertFalse(Optimiser::$regenerating);
		$this->assertFalse(has_filter('wp_update_attachment_metadata'));
		$this->assertSame(Status::OPTIMISED, Status::of($this->upload('graphic.png')));
	}

	public function test_a_new_thumbnail_size_is_made_and_optimised()
	{
		$id = $this->upload('photo.jpg');

		add_image_size('octopus-test', 200, 100, true);
		$this->assertSame(Status::OUTDATED, Status::of($id));
		$this->assertTrue(Optimiser::update($id));

		$metadata = wp_get_attachment_metadata($id);
		$this->assertArrayHasKey('octopus-test', $metadata['sizes']);
		$this->assertFileExists(Files::variant(dirname(get_attached_file($id)) . '/' . $metadata['sizes']['octopus-test']['file'], '.webp'));
		$this->assertSame(Status::OPTIMISED, Status::of($id));
	}

	public function test_the_maximum_size_scales_the_image_and_back()
	{
		self::set('max_size', 400);
		$id = $this->upload('photo.jpg');
		$scaled = get_attached_file($id);
		$this->assertStringEndsWith('photo-scaled.jpg', $scaled);
		$this->assertSame(400, wp_get_attachment_metadata($id)['width']);
		$this->assertFileExists(Files::variant($scaled, '.webp'));

		// No maximum any more: the image uploaded is the main file again.
		self::set('max_size', 0);
		$this->assertSame(Status::OUTDATED, Status::of($id));
		$this->assertTrue(Optimiser::update($id));

		$main = get_attached_file($id);
		$this->assertStringEndsWith('/photo.jpg', $main);
		$this->assertSame(640, wp_get_attachment_metadata($id)['width']);
		$this->assertFileExists(Files::variant($main, '.webp'));
		foreach ([$scaled, Files::variant($scaled, '.webp'), Files::variant($scaled, '.avif'), Files::backup_path($scaled)] as $file) {
			$this->assertFileDoesNotExist($file);
		}
		$this->assertFileEquals(self::fixture('photo.jpg'), Files::backup_path($main));
		$this->assertSame(Status::OPTIMISED, Status::of($id));
	}

	public function test_optimise_picks_its_mode_from_the_status()
	{
		$id = $this->upload_unoptimised('photo.jpg');

		$this->assertTrue(Optimiser::optimise($id));
		$this->assertSame(Status::OPTIMISED, Status::of($id));

		self::set('webp_quality', 40);
		$this->assertTrue(Optimiser::optimise($id));
		$this->assertSame('quality:40', get_post_meta($id, WebP::meta_key(), true));
		$this->assertSame(Status::OPTIMISED, Status::of($id));
	}

	public function test_the_images_are_counted_by_status()
	{
		$optimised = $this->upload('photo.jpg');
		$new = $this->upload_unoptimised('photo.jpg');
		$this->upload('damaged.jpg');

		$this->assertSame(['total' => 3, 'to-optimise' => 1, 'optimised' => 1, 'error' => 1, 'outdated' => 0], Status::counts());
		$this->assertSame([$new], Status::ids(Status::TO_OPTIMISE));

		self::set('webp_quality', 40);
		$this->assertSame(['total' => 3, 'to-optimise' => 1, 'optimised' => 0, 'error' => 1, 'outdated' => 1], Status::counts());
		$this->assertSame([$optimised], Status::ids(Status::OUTDATED));
	}

	public function test_deleting_the_image_deletes_its_files()
	{
		$id = $this->upload('photo.jpg');

		wp_delete_attachment($id, true);

		$left = [];
		$files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->uploads, \FilesystemIterator::SKIP_DOTS));
		foreach ($files as $file) {
			$left[] = substr($file->getPathname(), strlen($this->uploads) + 1);
		}
		sort($left);
		$originals = Files::relative(Files::backup_dir());
		$this->assertSame(['octopus/index.php', $originals . '/.htaccess', $originals . '/index.php'], $left);
	}

	public function test_an_image_deleted_while_it_is_processed_leaves_nothing()
	{
		$id = $this->upload_unoptimised('photo.jpg');
		// Deleted from the media library by another request, once the first library ran.
		add_filter('octopus_nice', function ($level) use ($id) {
			static $deleted = false;
			if (!$deleted) {
				$deleted = true;
				$cached = get_post($id);
				wp_delete_attachment($id, true);
				// Another request: this one still holds the image in its cache.
				wp_cache_set($id, $cached, 'posts');
			}
			return $level;
		});

		Optimiser::optimise($id);

		$left = [];
		$files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->uploads, \FilesystemIterator::SKIP_DOTS));
		foreach ($files as $file) {
			$left[] = substr($file->getPathname(), strlen($this->uploads) + 1);
		}
		$this->assertSame([], preg_grep('#\.(jpe?g|webp|avif)$#', $left), 'No version, no original.');
		$this->assertSame([], preg_grep('/^_octopus_/', array_keys(get_post_meta($id))), 'No meta without its image.');
	}

	public function test_deleting_the_image_leaves_a_file_of_the_same_name()
	{
		$id = $this->upload('photo.jpg');
		// E.g. another upload, named after the WebP version of this one.
		$other = get_attached_file($id) . '.webp';
		touch($other);

		wp_delete_attachment($id, true);

		$this->assertFileExists($other);
	}

	/**
	 * Checks that the PNG files of an image are lighter
	 * than their originals, still PNG files, with a WebP
	 * version unless it was heavier.
	 *
	 * @param int $id
	 */
	private function assert_png_compressed($id)
	{
		clearstatcache();
		foreach (self::files($id) as $file) {
			$this->assertLessThan(filesize(Files::backup_path($file)), filesize($file), $file);
			$this->assertSame('image/png', wp_getimagesize($file)['mime'], $file);
			$webp = Files::variant($file, '.webp');
			$this->assertTrue(file_exists($webp) || in_array(_wp_relative_upload_path($webp), Optimiser::heavier($id), true), $webp);
		}
		$this->assertSame(Status::OPTIMISED, Status::of($id));
	}
}
