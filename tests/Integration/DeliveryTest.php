<?php

/**
 * DeliveryTest
 *
 * The rewrite rules of uploads/.htaccess, and the
 * test of the delivery on the Delivery tab.
 *
 * @package     OIO
 * @author      Clément Gayot
 * @repo        https://github.com/Striffly/octopus-image-optimizer
 *
 */

namespace Octopus\Tests\Integration;

use Octopus\Admin\JobsAjax;
use Octopus\Delivery\Htaccess;
use Octopus\Delivery\Test;
use Octopus\Encoders\AVIF;
use Octopus\Encoders\WebP;
use Octopus\Media\Files;
use Octopus\Tests\TestCase;
use WPAjaxDieContinueException;

class DeliveryTest extends TestCase
{
	/**
	 * The server software of the request, put back
	 * after the test.
	 *
	 * @var string|null
	 */
	private $software;

	public function set_up()
	{
		parent::set_up();
		$this->software = isset($_SERVER['SERVER_SOFTWARE']) ? $_SERVER['SERVER_SOFTWARE'] : null;
		$_SERVER['SERVER_SOFTWARE'] = 'Apache/2.4.66 (Debian)';
	}

	public function tear_down()
	{
		if ($this->software === null) {
			unset($_SERVER['SERVER_SOFTWARE']);
		} else {
			$_SERVER['SERVER_SOFTWARE'] = $this->software;
		}
		unset($_REQUEST['_ajax_nonce']);
		parent::tear_down();
	}

	public function test_the_server_is_recognised()
	{
		$servers = [
			'Apache/2.4.66 (Debian)' => 'apache',
			'LiteSpeed' => 'apache',
			'nginx/1.28.0' => 'nginx',
			'Caddy' => 'other',
			'' => 'other',
		];
		foreach ($servers as $software => $server) {
			$_SERVER['SERVER_SOFTWARE'] = $software;
			$this->assertSame($server, Htaccess::server(), $software);
		}
	}

	public function test_the_rules_are_written_when_enabled_and_removed_when_disabled()
	{
		self::set(Htaccess::OPTION, true);

		$htaccess = file_get_contents(Htaccess::htaccess());
		$this->assertStringContainsString("# BEGIN Octopus Image Optimizer\n", $htaccess);
		foreach (Htaccess::apache_rules() as $rule) {
			$this->assertStringContainsString($rule . "\n", $htaccess);
		}
		$this->assertTrue(Htaccess::rules_written());

		self::set(Htaccess::OPTION, false);
		$this->assertFileDoesNotExist(Htaccess::htaccess(), 'It held the rules only.');
		$this->assertFalse(Htaccess::rules_written());
	}

	public function test_the_other_rules_of_the_file_are_kept()
	{
		$other = "# Another plugin\nOptions -Indexes\n";
		file_put_contents(Htaccess::htaccess(), $other);

		self::set(Htaccess::OPTION, true);
		$this->assertStringStartsWith($other, file_get_contents(Htaccess::htaccess()));

		// As many times as it is switched on and off.
		foreach ([false, true, false] as $on) {
			self::set(Htaccess::OPTION, $on);
		}
		$this->assertSame($other, file_get_contents(Htaccess::htaccess()));
	}

	public function test_the_rules_are_not_written_for_nginx()
	{
		$_SERVER['SERVER_SOFTWARE'] = 'nginx/1.28.0';

		self::set(Htaccess::OPTION, true);

		$this->assertFileDoesNotExist(Htaccess::htaccess());
		$this->assertStringContainsString('location ~* ^/wp-content/uploads/.+\.(jpe?g|png)$ {', Htaccess::nginx_rules());
	}

	public function test_a_working_delivery_passes_every_check()
	{
		$this->sample();
		$this->serve();

		$result = $this->run_test();

		$this->assertTrue($result['success']);
		$this->assertSame('photo.jpg', $result['data']['sample']);
		$this->assertSame(
			['ok', 'ok', 'ok', 'ok', 'ok'],
			array_column($result['data']['checks'], 'state'),
			wp_json_encode($result['data']['checks'])
		);
	}

	public function test_an_avif_served_with_another_type_fails()
	{
		$this->sample();
		$this->serve(['avif' => 'application/octet-stream', 'rewrite' => false]);

		$checks = $this->run_test()['data']['checks'];

		$this->assertSame('fail', $checks[1]['state']);
		$this->assertStringContainsString('AddType image/avif .avif', $checks[1]['advice']);
		$this->assertSame('info', $checks[2]['state'], 'The rewrite is off: not a failure.');
	}

	public function test_public_originals_fail()
	{
		$this->sample();
		$this->serve(['originals' => 200]);

		$checks = $this->run_test()['data']['checks'];
		$originals = end($checks);

		$this->assertSame('The original images are not public', $originals['label']);
		$this->assertSame('fail', $originals['state']);
		$this->assertStringContainsString('deny all;', $originals['advice']);
	}

	public function test_the_test_needs_an_optimised_image()
	{
		$result = $this->run_test();

		$this->assertFalse($result['success']);
		$this->assertStringContainsString('Optimise at least one image', $result['data']['message']);
	}

	/**
	 * Makes an optimised image, with its WebP and AVIF
	 * versions and its original.
	 */
	private function sample()
	{
		$id = self::factory()->attachment->create_object(['file' => '2026/10/photo.jpg', 'post_mime_type' => 'image/jpeg']);
		$file = $this->uploads . '/2026/10/photo.jpg';
		wp_mkdir_p(dirname($file));
		wp_mkdir_p(dirname(Files::backup_path($file)));
		foreach ([$file, $file . '.webp', $file . '.avif', Files::backup_path($file)] as $path) {
			touch($path);
		}
		update_post_meta($id, WebP::meta_key(), 'quality:80');
		update_post_meta($id, AVIF::meta_key(), 'quality:70');
	}

	/**
	 * Answers the requests of the test as a server
	 * would, without network.
	 *
	 * @param array $server The type of the AVIF files, whether the rewrite
	 *                      works, the status of the originals.
	 */
	private function serve($server = [])
	{
		$server = array_merge(['avif' => 'image/avif', 'rewrite' => true, 'originals' => 403], $server);
		add_filter('pre_http_request', function ($pre, $args, $url) use ($server) {
			$accept = isset($args['headers']['Accept']) ? $args['headers']['Accept'] : '';
			$status = 200;
			$vary = '';
			if (strpos($url, '/' . Files::BACKUP_DIR . '/') !== false) {
				list($status, $type) = [$server['originals'], 'image/jpeg'];
			} elseif (substr($url, -5) === '.avif') {
				$type = $server['avif'];
			} elseif (substr($url, -5) === '.webp') {
				$type = 'image/webp';
			} elseif ($server['rewrite'] && strpos($accept, 'image/avif') !== false) {
				list($type, $vary) = ['image/avif', 'Accept'];
			} else {
				$type = 'image/jpeg';
			}
			return [
				'headers' => ['content-type' => $type, 'vary' => $vary],
				'body' => '',
				'response' => ['code' => $status, 'message' => ''],
				'cookies' => [],
				'filename' => null,
			];
		}, 10, 3);
	}

	/**
	 * Runs the test as the AJAX request of the Delivery
	 * tab, by an administrator.
	 *
	 * @return array The JSON response.
	 */
	private function run_test()
	{
		wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
		if (is_multisite()) {
			grant_super_admin(get_current_user_id());
		}
		$_REQUEST['_ajax_nonce'] = wp_create_nonce(JobsAjax::NONCE);
		add_filter('wp_doing_ajax', '__return_true');
		add_filter('wp_die_ajax_handler', function () {
			return function () {
				throw new WPAjaxDieContinueException('');
			};
		});
		ob_start();
		try {
			(new Test())->test();
		} catch (WPAjaxDieContinueException $e) {
			// wp_send_json() ends the request.
		}
		return json_decode(ob_get_clean(), true);
	}
}
