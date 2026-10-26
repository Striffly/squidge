<?php

/**
 * TestCase
 *
 * Base of the integration tests: each test gets its
 * own uploads folder, removed after it, and the
 * libraries are looked up again.
 *
 * @package     OIO
 * @author      Clément Gayot
 * @repo        https://github.com/Striffly/octopus-image-optimizer
 *
 */

namespace OIO\Tests;

use OIO\Admin\Fields;
use OIO\Package\Optimiser;
use OIO\Package\Service;
use ReflectionProperty;
use WP_UnitTestCase;

abstract class TestCase extends WP_UnitTestCase
{
	/**
	 * The uploads folder of the test.
	 *
	 * @var string
	 */
	protected $uploads;

	/**
	 * Gives the test an empty uploads folder.
	 */
	public function set_up()
	{
		parent::set_up();
		$this->uploads = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'oio-tests-' . wp_generate_password(12, false) . DIRECTORY_SEPARATOR . 'uploads';
		wp_mkdir_p($this->uploads);
		add_filter('upload_dir', [$this, 'upload_dir']);
		Optimiser::$context = 'upload';
		self::forget_libraries();
	}

	/**
	 * Removes the uploads folder of the test. The hooks
	 * added by the test are removed by WordPress.
	 */
	public function tear_down()
	{
		self::remove(dirname($this->uploads));
		Service::forget_versions();
		parent::tear_down();
	}

	/**
	 * Points the uploads folder of WordPress to the one
	 * of the test, in sites/<id> for the other sites of
	 * a network, as WordPress does.
	 *
	 * @param array $dir
	 * @return array
	 */
	public function upload_dir($dir)
	{
		$base = $this->uploads;
		if (is_multisite() && !is_main_site()) {
			$base .= DIRECTORY_SEPARATOR . 'sites' . DIRECTORY_SEPARATOR . get_current_blog_id();
		}
		$dir['basedir'] = $base;
		$dir['path'] = $base . $dir['subdir'];
		return $dir;
	}

	/**
	 * Returns the path of a fixture.
	 *
	 * @param string $name
	 * @return string
	 */
	protected static function fixture($name)
	{
		return __DIR__ . DIRECTORY_SEPARATOR . 'Fixtures' . DIRECTORY_SEPARATOR . $name;
	}

	/**
	 * Uploads a fixture to the media library, as from
	 * the admin: OIO optimises it on upload.
	 *
	 * @param string $name
	 * @return int The attachment ID.
	 */
	protected function upload($name)
	{
		$id = self::factory()->attachment->create_upload_object(self::fixture($name));
		$this->assertIsInt($id, 'The fixture could not be uploaded.');
		return $id;
	}

	/**
	 * Uploads a fixture without optimising it, as an
	 * image of the media library from before OIO.
	 *
	 * @param string $name
	 * @return int The attachment ID.
	 */
	protected function upload_unoptimised($name)
	{
		$settings = Optimiser::SERVICES;
		foreach (array_keys($settings) as $format) {
			self::set('oio_' . $format . '_enable', false);
		}
		$id = $this->upload($name);
		foreach (array_keys($settings) as $format) {
			self::set('oio_' . $format . '_enable', true);
		}
		return $id;
	}

	/**
	 * Returns the files of an image: its main file,
	 * then each size, as in its metadata.
	 *
	 * @param int $id
	 * @return string[]
	 */
	protected static function files($id)
	{
		$metadata = wp_get_attachment_metadata($id);
		$main = get_attached_file($id);
		$files = [$main];
		foreach (isset($metadata['sizes']) ? $metadata['sizes'] : [] as $size) {
			$files[] = dirname($main) . DIRECTORY_SEPARATOR . $size['file'];
		}
		return array_values(array_unique($files));
	}

	/**
	 * Returns the hash of each file, by path.
	 *
	 * @param string[] $files
	 * @return string[]
	 */
	protected static function hashes($files)
	{
		clearstatcache();
		$hashes = [];
		foreach ($files as $file) {
			$hashes[$file] = file_exists($file) ? md5_file($file) : null;
		}
		return $hashes;
	}

	/**
	 * Checks the WebP or AVIF versions of an image: the
	 * main file has one, each thumbnail too unless it
	 * was deleted as heavier than its JPG or PNG, which
	 * depends on the version of the encoder.
	 *
	 * @param int $id
	 * @param string $extension .webp or .avif
	 * @param string $magic The bytes the format starts with.
	 * @param int $offset Where they are.
	 */
	protected function assert_versions($id, $extension, $magic, $offset)
	{
		foreach (self::files($id) as $index => $file) {
			$version = $file . $extension;
			if ($index > 0 && in_array(_wp_relative_upload_path($version), Optimiser::heavier($id), true)) {
				$this->assertFileDoesNotExist($version);
				continue;
			}
			$this->assertFileExists($version);
			$this->assertSame($magic, file_get_contents($version, false, null, $offset, strlen($magic)), $version);
		}
	}

	/**
	 * Saves a setting of the options page, as the form
	 * does.
	 *
	 * @param string $name The field, e.g. oio_jpg_quality.
	 * @param mixed $value True or false for a checkbox.
	 */
	protected static function set($name, $value)
	{
		if (is_bool($value)) {
			$value = $value ? Fields::CHECKBOX_ON : 'no';
		}
		update_option(Fields::OPTION_PREFIX . $name, $value);
	}

	/**
	 * Hides a library from OIO, as if it was not
	 * installed.
	 *
	 * @param string ...$names
	 */
	protected static function hide_libraries(...$names)
	{
		add_filter('oio_binary_path', function ($path, $name) use ($names) {
			return in_array($name, $names, true) ? false : $path;
		}, 10, 2);
		self::forget_libraries();
	}

	/**
	 * Skips the test unless the libraries are
	 * installed.
	 *
	 * @param string ...$names
	 */
	protected function require_libraries(...$names)
	{
		foreach ($names as $name) {
			if (!Service::installed($name)) {
				$this->markTestSkipped($name . ' is not installed.');
			}
		}
	}

	/**
	 * Skips the test unless the image editor of
	 * WordPress (GD or Imagick) can write the format.
	 *
	 * @param string $mime
	 */
	protected function require_editor($mime)
	{
		if (!wp_image_editor_supports(['mime_type' => $mime, 'methods' => ['save']])) {
			$this->markTestSkipped('The image editor cannot write ' . $mime . '.');
		}
	}

	/**
	 * Forgets the libraries and image editors looked up
	 * by OIO, kept for the request.
	 */
	protected static function forget_libraries()
	{
		foreach (['binaries' => [], 'editors' => [], 'versions' => null] as $name => $value) {
			$property = new ReflectionProperty(Service::class, $name);
			$property->setAccessible(true);
			$property->setValue(null, $value);
		}
	}

	/**
	 * Removes a folder and its content, without
	 * following its links.
	 *
	 * @param string $path
	 */
	protected static function remove($path)
	{
		if (is_link($path) || is_file($path)) {
			unlink($path);
			return;
		}
		if (!is_dir($path)) {
			return;
		}
		foreach (array_diff(scandir($path), ['.', '..']) as $entry) {
			self::remove($path . DIRECTORY_SEPARATOR . $entry);
		}
		rmdir($path);
	}
}
