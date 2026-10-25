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

use Squidge\Package\Status;
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
	 * Labels of the format states, see state().
	 *
	 * @var array
	 */
	const STATE_LABELS = [
		'on' => 'Active',
		'off' => 'Library missing',
		'muted' => 'Disabled',
	];

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
			// An emptied number field falls back to its default, not to 0.
			if ($field['type'] === 'number' && !is_numeric($value)) {
				return $field['default'];
			}
			return $value;
		}
		return null;
	}

	/**
	 * Registers custom admin style and script.
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
		wp_enqueue_style('squidge-admin', SQUIDGE_URL . 'assets/admin.css', [], filemtime(SQUIDGE_PATH . 'assets/admin.css'));
		wp_enqueue_script('squidge-admin', SQUIDGE_URL . 'assets/admin.js', [], filemtime(SQUIDGE_PATH . 'assets/admin.js'), true);
		wp_localize_script('squidge-admin', 'squidgeJob', [
			'ajaxurl' => admin_url('admin-ajax.php'),
			'nonce' => wp_create_nonce(Tools::NONCE),
		]);
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
		foreach (self::sections() as $section) {
			foreach ($section['fields'] as $name => $field) {
				register_setting(self::PAGE, self::OPTION_PREFIX . $name, [
					'sanitize_callback' => function ($value) use ($field) {
						return $this->sanitize($value, $field);
					},
				]);
			}
		}
	}

	/**
	 * Renders the options page: an overview tab with
	 * the health status of each library, then one
	 * tab per format. All tabs share one form so
	 * saving never resets the hidden tabs.
	 *
	 * @since 0.1.5
	 * @date 25/10/2026
	 */
	public function render_page()
	{
		if (!current_user_can('manage_options')) {
			return;
		}
		// Format sections have a library and a tab, the display section is shown in the overview.
		$sections = self::sections();
		$display = $sections['display'];
		unset($sections['display']);
		foreach ($sections as $id => $section) {
			$sections[$id]['installed'] = $section['service']::installed();
			$sections[$id]['enabled'] = self::get('squidge_' . $id . '_enable');
			$sections[$id]['library'] = $section['service']::cmd_name();
		}
		?>
		<div class="wrap squidge-page">
			<h1>Squidge</h1>

			<nav class="nav-tab-wrapper squidge-tabs" role="tablist">
				<a href="#overview" class="nav-tab nav-tab-active" role="tab" data-tab="overview">Overview</a>
				<?php foreach ($sections as $id => $section) : ?>
					<a href="#<?php echo esc_attr($id); ?>" class="nav-tab" role="tab" data-tab="<?php echo esc_attr($id); ?>">
						<span class="squidge-dot squidge-dot--<?php echo esc_attr(self::state($section)); ?>" aria-hidden="true"></span>
						<?php echo esc_html($section['title']); ?>
					</a>
				<?php endforeach; ?>
			</nav>

			<form action="options.php" method="post">
				<?php settings_fields(self::PAGE); ?>

				<section class="squidge-panel" id="squidge-panel-overview" role="tabpanel" data-panel="overview">
					<?php echo $this->render_template('info.php'); ?>
					<div class="squidge-status-grid">
						<?php foreach ($sections as $id => $section) : ?>
							<a class="squidge-status" href="#<?php echo esc_attr($id); ?>" data-tab="<?php echo esc_attr($id); ?>">
								<span class="squidge-status__head">
									<span class="squidge-status__format"><?php echo esc_html($section['title']); ?></span>
									<?php $this->render_badge(self::state($section), self::STATE_LABELS[self::state($section)]); ?>
								</span>
								<span class="squidge-status__library squidge-status__library--<?php echo $section['installed'] ? 'on' : 'off'; ?>">
									<code><?php echo esc_html($section['library']); ?></code>
									<span class="dashicons dashicons-<?php echo $section['installed'] ? 'yes' : 'no-alt'; ?>" aria-hidden="true"></span>
									<?php echo $section['installed'] ? 'installed' : 'not installed'; ?>
								</span>
							</a>
						<?php endforeach; ?>
					</div>
					<?php $this->render_library(); ?>
					<div class="squidge-card squidge-display">
						<header class="squidge-card__header squidge-card__header--text">
							<h2><?php echo esc_html($display['title']); ?></h2>
							<p class="description"><?php echo esc_html($display['description']); ?></p>
						</header>
						<?php foreach ($display['fields'] as $name => $field) : ?>
							<?php $this->render_field($name, $field); ?>
						<?php endforeach; ?>
					</div>
				</section>

				<?php foreach ($sections as $id => $section) : ?>
					<section class="squidge-panel" id="squidge-panel-<?php echo esc_attr($id); ?>" role="tabpanel" data-panel="<?php echo esc_attr($id); ?>">
						<div class="squidge-card">
							<header class="squidge-card__header">
								<h2><?php echo esc_html($section['title']); ?> <code><?php echo esc_html($section['library']); ?></code></h2>
								<?php $this->render_badge($section['installed'] ? 'on' : 'off', $section['installed'] ? 'Installed' : 'Not installed'); ?>
							</header>
							<?php if (!$section['installed']) : ?>
								<details class="squidge-install" open>
									<summary>How to install <?php echo esc_html($section['library']); ?></summary>
									<?php echo $this->render_template('health' . DIRECTORY_SEPARATOR . $section['template']); ?>
								</details>
							<?php endif; ?>
							<?php foreach ($section['fields'] as $name => $field) : ?>
								<?php $this->render_field($name, $field); ?>
							<?php endforeach; ?>
						</div>
					</section>
				<?php endforeach; ?>

				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Renders the number of JPG and PNG images of the
	 * media library by status.
	 *
	 * @since 0.1.5
	 * @date 25/10/2026
	 */
	private function render_library()
	{
		$counts = Status::counts();
		?>
		<div class="squidge-card squidge-library">
			<header class="squidge-card__header squidge-card__header--text">
				<h2>Media library</h2>
				<p class="description">
					<?php echo esc_html(sprintf(_n('%s JPG or PNG image', '%s JPG and PNG images', $counts['total']), number_format_i18n($counts['total']))); ?>
				</p>
			</header>
			<ul class="squidge-counts">
				<?php foreach (Media::LABELS as $status => $label) : ?>
					<li class="squidge-counts__item">
						<strong data-count="<?php echo esc_attr($status); ?>"><?php echo esc_html(number_format_i18n($counts[$status])); ?></strong>
						<span class="squidge-status-label squidge-status-label--<?php echo esc_attr($status); ?>"><?php echo esc_html($label); ?></span>
					</li>
				<?php endforeach; ?>
			</ul>
			<?php if (class_exists('Squidge_Vendor_WP_Background_Process')) : ?>
				<div class="squidge-job" hidden>
					<div class="squidge-job__actions">
						<button type="button" class="button button-primary" data-job="new">Optimise <span data-count="new"></span> images</button>
						<button type="button" class="button" data-job="outdated">Update <span data-count="outdated"></span> images with older settings</button>
						<button type="button" class="button-link" data-job="all">Force re-optimisation of all images</button>
					</div>
					<div class="squidge-job__progress">
						<p class="squidge-job__label" aria-live="polite"></p>
						<progress class="squidge-job__bar" max="100" value="0"></progress>
						<div class="squidge-job__controls">
							<button type="button" class="button" data-control="pause">Pause</button>
							<button type="button" class="button" data-control="resume">Resume</button>
							<button type="button" class="button-link button-link-delete" data-control="cancel">Cancel</button>
						</div>
					</div>
					<p class="squidge-job__result" aria-live="polite"></p>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Returns the state of a format, used for the
	 * tab dots and overview badges: on (enabled and
	 * installed), off (enabled but not installed)
	 * or muted (disabled).
	 *
	 * @param array $section
	 * @return string
	 * @since 0.1.5
	 * @date 25/10/2026
	 */
	private static function state($section)
	{
		if (!$section['enabled']) {
			return 'muted';
		}
		return $section['installed'] ? 'on' : 'off';
	}

	/**
	 * Renders a status badge.
	 *
	 * @param string $variant on, off or muted.
	 * @param string $label
	 * @since 0.1.5
	 * @date 25/10/2026
	 */
	private function render_badge($variant, $label)
	{
		printf('<span class="squidge-badge squidge-badge--%s">%s</span>', esc_attr($variant), esc_html($label));
	}

	/**
	 * Renders an option field.
	 *
	 * @param string $name
	 * @param array $field
	 * @since 0.1.5
	 * @date 25/10/2026
	 */
	private function render_field($name, $field)
	{
		$id = esc_attr(self::OPTION_PREFIX . $name);
		$value = self::get($name);

		if ($field['type'] === 'checkbox') {
			echo '<div class="squidge-field squidge-field--toggle">';
			printf(
				'<label class="squidge-toggle"><input type="checkbox" id="%s" name="%s" value="%s" %s><span class="squidge-toggle__track" aria-hidden="true"></span><span class="squidge-field__label">%s</span></label>',
				$id, $id, self::CHECKBOX_ON, checked($value, true, false), esc_html($field['label'])
			);
		} else {
			echo '<div class="squidge-field squidge-field--option">';
			printf('<label class="squidge-field__label" for="%s">%s</label>', $id, esc_html($field['label']));
			if ($field['type'] === 'number') {
				printf('<input type="number" class="small-text" id="%s" name="%s" value="%s" min="%d" max="%d" step="1">', $id, $id, esc_attr($value), $field['min'], $field['max']);
			} elseif ($field['type'] === 'select') {
				printf('<select id="%s" name="%s">', $id, $id);
				foreach ($field['options'] as $optionValue => $optionLabel) {
					printf('<option value="%s" %s>%s</option>', esc_attr($optionValue), selected((string)$value, (string)$optionValue, false), esc_html($optionLabel));
				}
				echo '</select>';
			}
		}

		printf('<p class="description">%s</p></div>', esc_html($field['help']));
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
				if (!is_numeric($value)) {
					return (string)$field['default'];
				}
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
				'service' => JPG::class,
				'template' => 'jpg.php',
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
				'service' => PNG::class,
				'template' => 'png.php',
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
							'0' => 'o0',
							'1' => 'o1',
							'2' => 'o2',
							'3' => 'o3',
							'4' => 'o4',
							'5' => 'o5',
							'6' => 'o6',
							'7' => 'o7',
						],
						'default' => '2',
						'help' => 'Select the optimisation level of the PNG compression, from o0 (fastest) to o7 (slowest, smallest files). The default is o2.',
					],
				],
			],
			'webp' => [
				'title' => 'WebP',
				'service' => WebP::class,
				'template' => 'webp.php',
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
				'service' => AVIF::class,
				'template' => 'avif.php',
				'fields' => [
					'squidge_avif_enable' => [
						'type' => 'checkbox',
						'label' => 'Enable AVIF Conversion',
						'default' => true,
						'help' => 'Select this box to enable AVIF conversion.',
					],
					'squidge_avif_quality' => [
						'type' => 'number',
						'label' => 'AVIF Quality',
						'min' => 0,
						'max' => 100,
						'default' => 70,
						'help' => 'Enter the quality of the AVIF image conversion, this can be a number from 0 to 100 (lossless), with the default being 70.',
					],
				],
			],
			'display' => [
				'title' => 'Display on the site',
				'description' => 'Your site already displays the compressed JPG and PNG images. Choose if the AVIF and WebP versions are used too.',
				'fields' => [
					'squidge_display_formats' => [
						'type' => 'checkbox',
						'label' => 'Use modern formats (WebP, AVIF)',
						'default' => false,
						'help' => 'Images of the post content, featured images and galleries are offered in AVIF and WebP, lighter modern formats. Each browser picks the best one it supports, and older browsers get the JPG or PNG. Technically, images are wrapped in a <picture> tag: untick this if your theme layout breaks around images.',
					],
				],
			],
		];
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
