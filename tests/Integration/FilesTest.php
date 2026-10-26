<?php

/**
 * FilesTest
 *
 * OIO reads, writes and deletes files in the uploads
 * folder and the folder of the originals only, and
 * passes their names to the libraries as given.
 *
 * @package     OIO
 * @author      Clément Gayot
 * @repo        https://github.com/Striffly/octopus-image-optimizer
 *
 */

namespace Octopus\Tests\Integration;

use Octopus\Package\Cleaner;
use Octopus\Package\Service;
use Octopus\Tests\TestCase;

class FilesTest extends TestCase
{
	/**
	 * A folder out of the uploads folder, removed with
	 * the one of the test.
	 *
	 * @var string
	 */
	private $outside;

	public function set_up()
	{
		parent::set_up();
		$this->outside = dirname($this->uploads) . DIRECTORY_SEPARATOR . 'outside';
		wp_mkdir_p($this->outside);
		wp_mkdir_p($this->uploads . '/2026/10');
	}

	public function test_the_paths_of_the_uploads_folder_are_contained()
	{
		touch($this->uploads . '/2026/10/a.jpg');

		$this->assertTrue(Service::contained($this->uploads . '/2026/10/a.jpg'));
		$this->assertTrue(Service::contained($this->uploads . '/2026/10/a.jpg.webp'), 'A file to create.');
		$this->assertTrue(Service::contained($this->uploads . '/2026/11/b.jpg'), 'In a folder to create.');
		$this->assertTrue(Service::contained(Service::backup_dir() . '/2026/10/a.jpg'));
	}

	public function test_the_paths_out_of_the_uploads_folder_are_not()
	{
		touch($this->outside . '/a.jpg');

		$this->assertFalse(Service::contained($this->outside . '/a.jpg'));
		$this->assertFalse(Service::contained($this->uploads . '/../outside/a.jpg'));
		$this->assertFalse(Service::contained($this->uploads . '/2026/10/../../../outside/a.jpg'));
		$this->assertFalse(Service::contained(ABSPATH . 'wp-config-sample.php'));
		$this->assertFalse(Service::contained($this->uploads), 'The folder itself.');
	}

	public function test_the_links_are_followed()
	{
		$this->require_links();
		touch($this->outside . '/a.jpg');
		symlink($this->outside . '/a.jpg', $this->uploads . '/2026/10/file-link.jpg');
		symlink($this->outside, $this->uploads . '/2026/10/folder-link');
		symlink($this->outside . '/missing.jpg', $this->uploads . '/2026/10/broken-link.jpg');

		$this->assertFalse(Service::contained($this->uploads . '/2026/10/file-link.jpg'));
		$this->assertFalse(Service::contained($this->uploads . '/2026/10/folder-link/a.jpg'));
		$this->assertFalse(Service::contained($this->uploads . '/2026/10/folder-link/new.jpg.webp'));
		$this->assertFalse(Service::contained($this->uploads . '/2026/10/broken-link.jpg'), 'Writing it would create its target.');
	}

	public function test_an_uploads_folder_that_is_a_link_is_contained()
	{
		$this->require_links();
		$link = dirname($this->uploads) . '/uploads-link';
		symlink($this->uploads, $link);
		add_filter('upload_dir', function ($dir) use ($link) {
			$dir['basedir'] = $link;
			$dir['path'] = $link . $dir['subdir'];
			return $dir;
		}, 20);

		$this->assertTrue(Service::contained($link . '/2026/10/a.jpg'));
	}

	public function test_an_image_linked_from_out_of_the_uploads_folder_is_not_touched()
	{
		$this->require_libraries('cwebp', 'jpegoptim');
		$this->require_links();
		$target = $this->outside . '/photo.jpg';
		copy(self::fixture('photo.jpg'), $target);
		$link = $this->uploads . '/2026/10/photo.jpg';
		symlink($target, $link);

		$id = self::factory()->attachment->create_object(['file' => '2026/10/photo.jpg', 'post_mime_type' => 'image/jpeg']);
		require_once ABSPATH . 'wp-admin/includes/image.php';
		wp_update_attachment_metadata($id, wp_generate_attachment_metadata($id, $link));

		$this->assertFileEquals(self::fixture('photo.jpg'), $target);
		$this->assertSame(['photo.jpg'], array_values(array_diff(scandir($this->outside), ['.', '..'])));
		$this->assertFileDoesNotExist($link . '.webp');
		$this->assertFileDoesNotExist(Service::backup_path($link));
		// The image is left as it is, its thumbnails too.
		$metadata = wp_get_attachment_metadata($id);
		$this->assertFileDoesNotExist($this->uploads . '/2026/10/' . $metadata['sizes']['thumbnail']['file'] . '.webp');

		// Deleting the image does not delete the file linked.
		wp_delete_attachment($id, true);
		$this->assertFileEquals(self::fixture('photo.jpg'), $target);
	}

	public function test_the_file_names_reach_the_libraries_as_given()
	{
		$this->require_libraries('cwebp', 'avifenc', 'jpegoptim');
		self::set('octopus_avif_quality', 30);
		// WordPress cleans the names of the files uploaded, not of the files
		// added by other means (FTP, plugins, WP-CLI).
		$name = "a b;touch octopus-pwned;\$(touch octopus-pwned)'\"`touch octopus-pwned`.jpg";
		$file = $this->uploads . '/2026/10/' . $name;
		copy(self::fixture('photo.jpg'), $file);

		$id = self::factory()->attachment->create_object(['file' => '2026/10/' . $name, 'post_mime_type' => 'image/jpeg']);
		require_once ABSPATH . 'wp-admin/includes/image.php';
		wp_update_attachment_metadata($id, wp_generate_attachment_metadata($id, $file));

		$this->assertFileExists($file . '.webp');
		$this->assertFileExists($file . '.avif');
		$this->assertFileEquals(self::fixture('photo.jpg'), Service::backup_path($file));
		foreach ([getcwd(), $this->uploads, $this->uploads . '/2026/10', ABSPATH] as $dir) {
			$this->assertFileDoesNotExist($dir . '/octopus-pwned');
		}
	}

	public function test_the_originals_can_be_moved_out_of_the_uploads_folder()
	{
		$this->require_libraries('jpegoptim');
		$originals = $this->outside . '/originals';
		add_filter('octopus_backup_dir', function () use ($originals) {
			return $originals;
		});

		$id = $this->upload('photo.jpg');
		$main = get_attached_file($id);
		$backup = $originals . '/' . _wp_relative_upload_path($main);
		$this->assertSame($backup, Service::backup_path($main));
		$this->assertFileEquals(self::fixture('photo.jpg'), $backup);
		$this->assertDirectoryDoesNotExist($this->uploads . '/' . Service::BACKUP_DIR);

		$this->assertTrue(Cleaner::restore($id));
		$this->assertFileEquals(self::fixture('photo.jpg'), $main);
		$this->assertFileDoesNotExist($backup);
	}

	public function test_without_its_original_a_compressed_image_stays_listed()
	{
		$this->require_libraries('jpegoptim');
		$id = $this->upload('photo.jpg');
		$main = get_attached_file($id);
		$compressed = md5_file($main);

		// The folder of the originals moved, and the filter was forgotten.
		add_filter('octopus_backup_dir', function () {
			return $this->outside . '/elsewhere';
		});

		$this->assertFalse(Cleaner::restore($id));
		$this->assertSame($compressed, md5_file($main));
		$this->assertNotEmpty(get_post_meta($id, Service::FILES_KEY, true), 'The originals stay listed, to restore them once the folder is back.');
	}

	/**
	 * Skips the test where PHP cannot make links.
	 */
	private function require_links()
	{
		if (!function_exists('symlink') || PHP_OS_FAMILY === 'Windows') {
			$this->markTestSkipped('PHP cannot make links here.');
		}
	}
}
