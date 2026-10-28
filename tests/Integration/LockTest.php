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

namespace Octopus\Tests\Integration;

use Exception;
use Octopus\Engines\Libraries;
use Octopus\Media\Cleaner;
use Octopus\Media\Files;
use Octopus\Media\Lock;
use Octopus\Media\Optimiser;
use Octopus\Tests\TestCase;

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
		$this->assertFileDoesNotExist(Files::variant(get_attached_file($id), '.webp'));
		$this->assertTrue(self::locked($id), 'The lock of the other request stays.');
	}

	public function test_a_stale_lock_is_taken_over()
	{
		$this->require_libraries('cwebp');
		$id = $this->upload_unoptimised('photo.jpg');
		self::lock($id, time() - Lock::TIMEOUT - 1);

		$this->assertTrue(Optimiser::attachment($id, null, Optimiser::settings()));
		$this->assertFileExists(Files::variant(get_attached_file($id), '.webp'));
		$this->assertFalse(self::locked($id));
	}

	public function test_the_stale_locks_of_the_images_are_swept()
	{
		// E.g. left by a worker PHP stopped.
		self::lock(7, time() - Lock::TIMEOUT - 1);
		self::lock(8, time());
		self::lock('worker_job', time() - Lock::TIMEOUT - 1);

		Lock::sweep();

		$this->assertFalse(self::locked(7));
		$this->assertTrue(self::locked(8), 'Still held.');
		$this->assertTrue(self::locked('worker_job'), 'A worker has its own timeout.');
	}

	public function test_the_time_an_image_may_still_be_held_is_told()
	{
		self::lock(7, time() - 20);

		$this->assertEqualsWithDelta(Lock::TIMEOUT - 20, Lock::left(7), 1);
		$this->assertSame(0, Lock::left(8), 'Free.');
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

	public function test_the_lock_is_refreshed_while_the_image_is_processed()
	{
		$values = Lock::run(42, function () {
			$before = self::value(42);
			usleep(1000);
			Lock::touch(42);
			return [$before, self::value(42)];
		});

		$this->assertGreaterThan((float)$values[0], (float)$values[1]);
	}

	public function test_a_lock_taken_over_is_left_to_the_other_request()
	{
		Lock::run(42, function () {
			// Stale for another request, which took it over.
			global $wpdb;
			$wpdb->update($wpdb->options, ['option_value' => '1.000000'], ['option_name' => Lock::PREFIX . 42]);
		});

		$this->assertTrue(self::locked(42));
	}

	public function test_a_library_taking_too_long_is_stopped()
	{
		if (PHP_OS_FAMILY === 'Windows') {
			$this->markTestSkipped('sleep is not a program on Windows.');
		}
		add_filter('octopus_timeout', function () {
			return 1;
		});
		$start = microtime(true);

		list($code, $output) = Libraries::execute(['sleep', '10']);

		$this->assertSame(124, $code);
		$this->assertStringContainsString('sleep took longer than 1 s, stopped', $output);
		$this->assertLessThan(3, microtime(true) - $start);
	}

	public function test_the_output_of_a_library_is_read_until_it_ends()
	{
		if (PHP_OS_FAMILY === 'Windows') {
			$this->markTestSkipped('printf is not a program on Windows.');
		}

		$this->assertSame([3, 'line'], Libraries::execute(['sh', '-c', 'printf line; exit 3']));
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
	 * Returns the value of the lock of an image.
	 *
	 * @param int $id
	 * @return string|null
	 */
	private static function value($id)
	{
		global $wpdb;
		return $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", Lock::PREFIX . $id));
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
