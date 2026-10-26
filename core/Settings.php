<?php

/**
 * Settings
 *
 * The settings of OIO, in one option: what each one
 * is (type, default, bounds), reading and saving
 * them. The settings page renders them, see
 * Admin\SettingsPage.
 *
 * @package     OIO
 * @author      Clément Gayot
 * @category    Class
 * @repo        https://github.com/Striffly/octopus-image-optimizer
 *
 */

namespace Octopus;

if (!defined('ABSPATH')) {
	exit; // Exit if accessed directly
}

class Settings
{
	/**
	 * The option holding the settings, an array by
	 * name.
	 *
	 * @var string
	 */
	const OPTION = 'octopus_settings';

	/**
	 * The settings group of the form of the settings
	 * page (see settings_fields()).
	 *
	 * @var string
	 */
	const GROUP = 'octopus-image-optimizer';

	/**
	 * The settings: their type (bool, int with its
	 * bounds, or choice with its values) and default.
	 *
	 * @var array
	 */
	const FIELDS = [
		'jpg_enable' => ['type' => 'bool', 'default' => true],
		'jpg_quality' => ['type' => 'int', 'min' => 0, 'max' => 100, 'default' => 80],
		'png_enable' => ['type' => 'bool', 'default' => true],
		'png_compression' => ['type' => 'choice', 'values' => ['lossless', 'lossy'], 'default' => 'lossless'],
		'png_level' => ['type' => 'int', 'min' => 0, 'max' => 7, 'default' => 2],
		'png_lossy_quality' => ['type' => 'int', 'min' => 0, 'max' => 100, 'default' => 80],
		'webp_enable' => ['type' => 'bool', 'default' => true],
		'webp_quality' => ['type' => 'int', 'min' => 0, 'max' => 100, 'default' => 80],
		'avif_enable' => ['type' => 'bool', 'default' => true],
		'avif_quality' => ['type' => 'int', 'min' => 0, 'max' => 100, 'default' => 70],
		'max_size' => ['type' => 'int', 'min' => 0, 'max' => 20000, 'default' => 2560],
		'serve_picture' => ['type' => 'bool', 'default' => false],
		'serve_rewrite' => ['type' => 'bool', 'default' => false],
	];

	/**
	 * Registers the option, so that every saving is
	 * cleaned: by the settings page, the REST API or
	 * code. On init.
	 *
	 * @since 1.0.0
	 */
	public static function register()
	{
		register_setting(self::GROUP, self::OPTION, [
			'type' => 'object',
			'default' => [],
			'sanitize_callback' => [self::class, 'sanitize'],
		]);
	}

	/**
	 * Returns a setting, its default if it was never
	 * saved.
	 *
	 * @param string $name
	 * @return bool|int|string|null Null for an unknown setting.
	 * @since 1.0.0
	 */
	public static function get($name)
	{
		if (!isset(self::FIELDS[$name])) {
			return null;
		}
		$saved = get_option(self::OPTION, []);
		if (!is_array($saved) || !array_key_exists($name, $saved)) {
			return self::FIELDS[$name]['default'];
		}
		return self::clean($name, $saved[$name]);
	}

	/**
	 * Returns every setting, by name.
	 *
	 * @return array
	 * @since 1.0.0
	 */
	public static function all()
	{
		$values = [];
		foreach (array_keys(self::FIELDS) as $name) {
			$values[$name] = self::get($name);
		}
		return $values;
	}

	/**
	 * Saves settings, the others keeping their value.
	 *
	 * @param array $values By name.
	 * @since 1.0.0
	 */
	public static function set($values)
	{
		update_option(self::OPTION, array_merge(self::all(), $values));
	}

	/**
	 * Cleans the settings sent by the settings page
	 * before they are saved: every setting is there,
	 * of its type, within its bounds. A checkbox left
	 * unticked is not sent: it is off.
	 *
	 * @param mixed $input
	 * @return array
	 * @since 1.0.0
	 */
	public static function sanitize($input)
	{
		$input = is_array($input) ? $input : [];
		$values = [];
		foreach (self::FIELDS as $name => $field) {
			if ($field['type'] === 'bool') {
				$values[$name] = !empty($input[$name]);
			} else {
				$values[$name] = self::clean($name, isset($input[$name]) ? $input[$name] : null);
			}
		}
		return $values;
	}

	/**
	 * Returns a value of a setting, of its type and
	 * within its bounds, or its default.
	 *
	 * @param string $name
	 * @param mixed $value
	 * @return bool|int|string
	 * @since 1.0.0
	 */
	private static function clean($name, $value)
	{
		$field = self::FIELDS[$name];
		switch ($field['type']) {
			case 'bool':
				return (bool)$value;
			case 'int':
				// An emptied number field falls back to its default, not to 0.
				return is_numeric($value) ? max($field['min'], min($field['max'], (int)$value)) : $field['default'];
			default:
				return in_array($value, $field['values'], true) ? $value : $field['default'];
		}
	}
}
