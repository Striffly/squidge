<?php

/**
 * SettingsAjax
 *
 * SettingsAjax saves the settings of the options page
 * one by one, as they change (Form.js), without the
 * button of the form.
 *
 * @package     OIO
 * @author      Clément Gayot
 * @category    Admin
 * @repo        https://github.com/Striffly/octopus-image-optimizer
 *
 */

namespace Octopus\Admin;

use Octopus\Encoders\Formats;
use Octopus\Settings;

if (!defined('ABSPATH')) {
	exit; // Exit if accessed directly
}

class SettingsAjax
{
	/**
	 * Adds the AJAX request.
	 *
	 * @since 1.0.0
	 */
	public function __construct()
	{
		add_action('wp_ajax_octopus_settings_save', [$this, 'save']);
	}

	/**
	 * Saves one setting, cleaned as the form saves them
	 * (Settings::sanitize()), and sends its value back:
	 * the page shows it as saved, e.g. a number brought
	 * within its bounds. And the error of what follows
	 * it, if any, as the form shows it: e.g. the rules
	 * of uploads/.htaccess not written. The nonce is the
	 * one of the requests of the page.
	 *
	 * @since 1.0.0
	 */
	public function save()
	{
		check_ajax_referer(Ajax::NONCE);
		Ajax::authorize();
		$name = isset($_POST['name']) ? sanitize_key(wp_unslash($_POST['name'])) : '';
		if (!array_key_exists($name, Settings::FIELDS)) {
			wp_send_json_error(null, 400);
		}
		$value = isset($_POST['value']) ? sanitize_text_field(wp_unslash($_POST['value'])) : '';
		// A value the page greys, e.g. lossy without pngquant: sent by hand.
		if (Formats::unavailable($name, $value)) {
			wp_send_json_error(null, 400);
		}
		Settings::set([$name => $value]);
		$errors = wp_list_pluck(get_settings_errors(Settings::OPTION), 'message');
		wp_send_json_success(['value' => Settings::get($name), 'error' => $errors ? wp_strip_all_tags(implode(' ', $errors)) : '']);
	}
}
