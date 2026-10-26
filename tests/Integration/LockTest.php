<?php

/**
 * LockTest
 *
 * One request at a time processes an image.
 *
 * @package     OIO
 * @author      Clément Gayot
 * @repo        https://github.com/Striffly/octopus-image-optimizer
 *
 */

namespace OIO\Tests\Integration;

use Exception;
use OIO\Package\Cleaner;
use OIO\Package\Lock;
use OIO\Package\Optimiser;
use OIO\Tests\TestCase;

class LockTest extends TestCase
{
	public function test_an_image_locked_by_another_request_is_left_to_it()
	{
		$this->require_libraries('cwebp');
		$id = $this->upload_unoptimised('photo.jpg');
		self::lock($id, time());

		$this->assertNull(Optimiser::attachment($id, null, Optimiser::settings()));
		$this->assertNull(Optimiser::update($id));
		$this->assertNull(Cleaner::restore($id));
		$this->assertFileDoesNotExist(get_attached_file($id) . '.webp');
		$this->assertTrue(self::locked($id), 'The lock of the other request stays.');
	}

	public function test_a_stale_lock_is_taken_over()
	{
		$this->require_libraries('cwebp');
		$id = $this->upload_unoptimised('photo.jpg');
		self::lock($id, time() - Lock::TIMEOUT - 1);

		$this->assertTrue(Optimiser::attachment($id, null, Optimiser::settings()));
		$this->assertFileExists(get_attached_file($id) . '.webp');
		$this->assertFalse(self::locked($id));
	}

	public function test_a_request_holding_the_lock_goes_on()
	{
		$result = Lock::run(42, function () {
			return Lock::run(42, function () {
				return 'nested';
			});
		});

		$this->assertSame('nested', $result);
		$this->assertFalse(self::locked(42));
	}

	public function test_the_lock_is_released_after_an_error()
	{
		try {
			Lock::run(42, function () {
				throw new Exception('failed');
			});
			$this->fail('The exception is thrown again.');
		} catch (Exception $e) {
			$this->assertSame('failed', $e->getMessage());
		}

		$this->assertFalse(self::locked(42));
	}

	/**
	 * Locks an image as another request would.
	 *
	 * @param int $id
	 * @param int $time When it was locked.
	 */
	private static function lock($id, $time)
	{
		global $wpdb;
		$wpdb->insert($wpdb->options, ['option_name' => Lock::PREFIX . $id, 'option_value' => $time, 'autoload' => 'no']);
	}

	/**
	 * Determines if an image is locked.
	 *
	 * @param int $id
	 * @return bool
	 */
	private static function locked($id)
	{
		global $wpdb;
		return (bool)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name = %s", Lock::PREFIX . $id));
	}
}
