<?php

/**
 * SettingsAjaxTest
 *
 * The settings saved one by one from the options page
 * (SettingsAjax, Form.js).
 *
 * @package     OIO
 * @author      Clément Gayot
 * @category    Tests
 * @repo        https://github.com/Striffly/octopus-image-optimizer
 *
 */

namespace Octopus\Tests\Integration;

use Octopus\Admin\JobsAjax;
use Octopus\Admin\SettingsAjax;
use Octopus\Delivery\Htaccess;
use Octopus\Settings;
use Octopus\Tests\TestCase;
use WPAjaxDieContinueException;
use WPAjaxDieStopException;

class SettingsAjaxTest extends TestCase
{
	public function tear_down()
	{
		unset($_REQUEST['_ajax_nonce'], $_POST['name'], $_POST['value']);
		parent::tear_down();
	}

	public function test_a_setting_is_saved_as_the_form_cleans_it()
	{
		$response = $this->save('jpg_quality', '150');

		$this->assertTrue($response['success']);
		$this->assertSame(100, $response['data']['value']);
		$this->assertSame(100, Settings::get('jpg_quality'));
		$this->assertSame('', $response['data']['error']);
	}

	public function test_the_rules_not_written_are_told()
	{
		$_SERVER['SERVER_SOFTWARE'] = 'Apache/2.4.66 (Debian)';
		wp_mkdir_p(dirname(Htaccess::htaccess()));
		touch(Htaccess::htaccess());
		chmod(Htaccess::htaccess(), 0444);

		$response = $this->save('serve_rewrite', '1');
		chmod(Htaccess::htaccess(), 0644);
		unset($_SERVER['SERVER_SOFTWARE']);

		// Saved, as the form saves it: the rules can be copied by hand.
		$this->assertTrue($response['success']);
		$this->assertTrue($response['data']['value']);
		$this->assertStringContainsString('could not write the rules', $response['data']['error']);
	}

	public function test_the_nonce_of_the_background_library_is_refused()
	{
		// Made for the same user, it goes in the URL of the requests of the library.
		$this->expectException(WPAjaxDieStopException::class);

		$this->save('jpg_quality', '50', 'octopus_job');
	}

	/**
	 * Saves a setting as Form.js does, by an
	 * administrator.
	 *
	 * @param string $name
	 * @param string $value
	 * @param string $nonce The nonce action.
	 * @return array The JSON response.
	 */
	private function save($name, $value, $nonce = JobsAjax::NONCE)
	{
		wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
		if (is_multisite()) {
			grant_super_admin(get_current_user_id());
		}
		$_REQUEST['_ajax_nonce'] = wp_create_nonce($nonce);
		$_POST['name'] = $name;
		$_POST['value'] = $value;
		add_filter('wp_doing_ajax', '__return_true');
		add_filter('wp_die_ajax_handler', function () {
			return function ($message) {
				// check_ajax_referer() dies with -1, wp_send_json() ends the request.
				if ($message === -1 || $message === '-1') {
					throw new WPAjaxDieStopException('-1');
				}
				throw new WPAjaxDieContinueException('');
			};
		});
		ob_start();
		try {
			(new SettingsAjax())->save();
		} catch (WPAjaxDieContinueException $e) {
			// The response is sent.
		} finally {
			$output = ob_get_clean();
		}
		return json_decode($output, true);
	}
}
