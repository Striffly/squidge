<?php

/**
 * Fields
 *
 * Register the options page for the admin
 * part of the plugin, using the WordPress
 * Settings API.
 *
 * @package     Squidge
 * @version     0.1.4
 * @author      Ainsley Clark
 * @category    Class
 * @repo        https://github.com/ainsleyclark/squidge
 *
 */

namespace Squidge\Admin;

use Squidge\Services\AVIF;
use Squidge\Services\JPG;
use Squidge\Services\PNG;
use Squidge\Services\WebP;

if (!defined('ABSPATH')) {
	exit; // Exit if accessed directly
}

class Fields
{
	/**
	 * The options page slug.
	 *
	 * @var string
	 */
	const PAGE = 'squidge';

	/**
	 * Prefix of the option names in the database,
	 * kept from Carbon Fields so existing settings
	 * are still read.
	 *
	 * @var string
	 */
	const OPTION_PREFIX = '_';

	/**
	 * Value stored for a checked checkbox.
	 *
	 * @var string
	 */
	const CHECKBOX_ON = 'yes';

	/**
	 * Registers the options page.
	 *
	 * @since 0.1.0
	 * @date 24/11/2021
	 */
	public function __construct()
	{
		add_action('admin_menu', [$this, 'add_page']);
		add_action('admin_init', [$this, 'register_fields']);
		add_action('admin_enqueue_scripts', [$this, 'styles_and_scripts']);
	}

	/**
	 * Returns the URL of the options page.
	 *
	 * @return string
	 * @since 0.1.5
	 * @date 25/10/2026
	 */
	public static function page_url()
	{
		return admin_url('options-general.php?page=' . self::PAGE);
	}

	/**
	 * Returns the value of a Squidge option, or its
	 * default if it has never been saved.
	 *
	 * @param string $name
	 * @return mixed
	 * @since 0.1.5
	 * @date 25/10/2026
	 */
	public static function get($name)
	{
		foreach (self::sections() as $section) {
			if (!isset($section['fields'][$name])) {
				continue;
			}
			$field = $section['fields'][$name];
			$value = get_option(self::OPTION_PREFIX . $name, null);
			if ($value === null) {
				return $field['default'];
			}
			if ($field['type'] === 'checkbox') {
				return $value === self::CHECKBOX_ON;
			}
			return $value;
		}
		return null;
	}

	/**
	 * Registers custom admin style.
	 *
	 * @param string $hook
	 * @since 0.1.0
	 * @date 24/11/2021
	 */
	public function styles_and_scripts($hook)
	{
		if ($hook !== 'settings_page_' . self::PAGE) {
			return;
		}
		wp_enqueue_style('squidge-admin', SQUIDGE_URL . 'assets/admin.css', [], SQUIDGE_VERSION);
	}

	/**
	 * Adds the options page under Settings.
	 *
	 * @since 0.1.5
	 * @date 25/10/2026
	 */
	public function add_page()
	{
		add_options_page('Squidge', 'Squidge Options', 'manage_options', self::PAGE, [$this, 'render_page']);
	}

	/**
	 * Registers the admin fields (options).
	 *
	 * @since 0.1.0
	 * @date 24/11/2021
	 */
	public function register_fields()
	{
		add_settings_section('squidge_health', '', [$this, 'render_info'], self::PAGE);
		$this->add_health_field('jpg', 'jpg.php', 'JPG', JPG::installed());
		$this->add_health_field('png', 'png.php', 'PNG', PNG::installed());
		$this->add_health_field('webp', 'webp.php', 'WebP', WebP::installed());
		$this->add_health_field('avif', 'avif.php', 'AVIF', AVIF::installed());

		foreach (self::sections() as $id => $section) {
			add_settings_section('squidge_' . $id, $section['title'], '__return_false', self::PAGE);
			foreach ($section['fields'] as $name => $field) {
				$option = self::OPTION_PREFIX . $name;
				register_setting(self::PAGE, $option, [
					'sanitize_callback' => function ($value) use ($field) {
						return $this->sanitize($value, $field);
					},
				]);
				$field['name'] = $name;
				add_settings_field($option, $field['label'], [$this, 'render_field'], self::PAGE, 'squidge_' . $id, [
					'label_for' => $option,
					'field' => $field,
				]);
			}
		}
	}

	/**
	 * Renders the options page.
	 *
	 * @since 0.1.5
	 * @date 25/10/2026
	 */
	public function render_page()
	{
		if (!current_user_can('manage_options')) {
			return;
		}
		?>
		<div class="wrap">
			<form action="options.php" method="post">
				<?php
				settings_fields(self::PAGE);
				do_settings_sections(self::PAGE);
				submit_button();
				?>
			</form>
		</div>
		<?php
	}

	/**
	 * Renders the information block above the
	 * health statuses.
	 *
	 * @since 0.1.5
	 * @date 25/10/2026
	 */
	public function render_info()
	{
		echo $this->render_template('info.php');
	}

	/**
	 * Renders an option field.
	 *
	 * @param array $args
	 * @since 0.1.5
	 * @date 25/10/2026
	 */
	public function render_field($args)
	{
		$field = $args['field'];
		$id = esc_attr($args['label_for']);
		$value = self::get($field['name']);

		switch ($field['type']) {
			case 'checkbox':
				printf('<input type="checkbox" id="%s" name="%s" value="%s" %s>', $id, $id, self::CHECKBOX_ON, checked($value, true, false));
				break;
			case 'number':
				printf('<input type="number" class="small-text" id="%s" name="%s" value="%s" min="%d" max="%d" step="1">', $id, $id, esc_attr($value), $field['min'], $field['max']);
				break;
			case 'select':
				printf('<select id="%s" name="%s">', $id, $id);
				foreach ($field['options'] as $optionValue => $optionLabel) {
					printf('<option value="%s" %s>%s</option>', esc_attr($optionValue), selected((string)$value, (string)$optionValue, false), esc_html($optionLabel));
				}
				echo '</select>';
				break;
		}

		printf('<p class="description">%s</p>', esc_html($field['help']));
	}

	/**
	 * Sanitizes an option value before it is saved.
	 *
	 * @param mixed $value
	 * @param array $field
	 * @return string
	 * @since 0.1.5
	 * @date 25/10/2026
	 */
	private function sanitize($value, $field)
	{
		switch ($field['type']) {
			case 'checkbox':
				return $value === self::CHECKBOX_ON ? self::CHECKBOX_ON : '';
			case 'number':
				return (string)max($field['min'], min($field['max'], (int)$value));
			case 'select':
				return array_key_exists((string)$value, $field['options']) ? (string)$value : (string)$field['default'];
		}
		return '';
	}

	/**
	 * Returns the option fields, grouped by section.
	 *
	 * @return array
	 * @since 0.1.5
	 * @date 25/10/2026
	 */
	private static function sections()
	{
		return [
			'jpg' => [
				'title' => 'JPG',
				'fields' => [
					'squidge_jpg_enable' => [
						'type' => 'checkbox',
						'label' => 'Enable JPEG Compression',
						'default' => true,
						'help' => 'Select this box to enable JPEG compression.',
					],
					'squidge_jpg_quality' => [
						'type' => 'number',
						'label' => 'JPEG Optim Quality',
						'min' => 0,
						'max' => 100,
						'default' => 80,
						'help' => 'Enter the quality of the JPEG image compression, this can be a number from 0 to 100, with the default being 80.',
					],
				],
			],
			'png' => [
				'title' => 'PNG',
				'fields' => [
					'squidge_png_enable' => [
						'type' => 'checkbox',
						'label' => 'Enable PNG Compression',
						'default' => true,
						'help' => 'Select this box to enable PNG compression.',
					],
					'squidge_png_quality' => [
						'type' => 'select',
						'label' => 'Opti PNG Level',
						'options' => [
							'0' => 'o1',
							'1' => 'o2',
							'2' => 'o3',
							'3' => 'o5',
							'4' => 'o4',
							'5' => 'o5',
							'6' => 'o6',
							'7' => 'o7',
						],
						'default' => '3',
						'help' => 'Enter the optimisation level of the PNG compression. The default is o2 with o7 being the fastest and o1 being the slowest.',
					],
				],
			],
			'webp' => [
				'title' => 'WebP',
				'fields' => [
					'squidge_webp_enable' => [
						'type' => 'checkbox',
						'label' => 'Enable WebP Conversion',
						'default' => true,
						'help' => 'Select this box to enable webp conversion.',
					],
					'squidge_webp_quality' => [
						'type' => 'number',
						'label' => 'WebP Quality',
						'min' => 0,
						'max' => 100,
						'default' => 80,
						'help' => 'Enter the quality of the webp image conversion, this can be a number from 0 to 100, with the default being 80.',
					],
				],
			],
			'avif' => [
				'title' => 'AVIF',
				'fields' => [
					'squidge_avif_enable' => [
						'type' => 'checkbox',
						'label' => 'Enable AVIF Conversion',
						'default' => true,
						'help' => 'Select this box to enable AVIF conversion.',
					],
				],
			],
		];
	}

	/**
	 * Adds a health status field dependent on if
	 * it is valid or installed. Classes and
	 * attributes will be set accordingly.
	 *
	 * @param $name
	 * @param $templateName
	 * @param $niceName
	 * @param $valid
	 * @since 0.1.0
	 * @date 24/11/2021
	 */
	private function add_health_field($name, $templateName, $niceName, $valid)
	{
		add_settings_field('squidge_' . $name . '_status', $niceName . ' Status', function () use ($templateName, $valid) {
			printf(
				'<div class="squidge squidge-disabled squidge-health squidge-health-%s"><input type="text" readonly tabindex="-1" placeholder="%s"><div class="squidge-help">%s</div></div>',
				$valid ? 'active' : 'inactive',
				$valid ? 'Active' : 'Inactive',
				$this->get_health_text('health' . DIRECTORY_SEPARATOR . $templateName, $valid)
			);
		}, self::PAGE, 'squidge_health');
	}

	/**
	 * Renders the health template.
	 *
	 * @param $templateName
	 * @param $valid
	 * @return false|string
	 * @since 0.1.0
	 * @date 24/11/2021
	 */
	private function get_health_text($templateName, $valid)
	{
		global $health_valid;
		$health_valid = $valid;
		return $this->render_template($templateName);
	}

	/**
	 * Renders a Squidge template in the /templates
	 * folder.
	 *
	 * @param $name
	 * @return false|string
	 * @since 0.1.0
	 * @date 24/11/2021
	 */
	private function render_template($name)
	{
		ob_start();
		include SQUIDGE_TEMPLATE_PATH . DIRECTORY_SEPARATOR . $name;
		return ob_get_clean();
	}
}
