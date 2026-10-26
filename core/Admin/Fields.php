<?php

/**
 * Fields
 *
 * Register the options page for the admin
 * part of the plugin, using the WordPress
 * Settings API.
 *
 * @package     OIO
 * @author      Clément Gayot
 * @author      Ainsley Clark (Squidge, original author)
 * @category    Class
 * @repo        https://github.com/Striffly/octopus-image-optimizer
 *
 */

namespace OIO\Admin;

use OIO\Package\Cleaner;
use OIO\Package\Service;
use OIO\Package\Stats;
use OIO\Package\Status;
use OIO\Services\AVIF;
use OIO\Services\JPG;
use OIO\Services\PNG;
use OIO\Services\WebP;

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
	const PAGE = 'oio';

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
	 * @since 1.0.0
	 * @date 25/10/2026
	 */
	public static function page_url()
	{
		return admin_url('options-general.php?page=' . self::PAGE);
	}

	/**
	 * Returns the value of an OIO option, or its
	 * default if it has never been saved.
	 *
	 * @param string $name
	 * @return mixed
	 * @since 1.0.0
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
		wp_enqueue_style('oio-admin', OIO_URL . 'assets/admin.css', [], filemtime(OIO_PATH . 'assets/admin.css'));
		wp_enqueue_script('oio-admin', OIO_URL . 'assets/admin.js', [], filemtime(OIO_PATH . 'assets/admin.js'), true);
		wp_localize_script('oio-admin', 'oioJob', [
			'ajaxurl' => admin_url('admin-ajax.php'),
			'nonce' => wp_create_nonce(Tools::NONCE),
		]);
	}

	/**
	 * Adds the options page under Settings.
	 *
	 * @since 1.0.0
	 * @date 25/10/2026
	 */
	public function add_page()
	{
		add_options_page('OIO – Octopus Image Optimizer', 'OIO', 'manage_options', self::PAGE, [$this, 'render_page']);
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
	 * Renders the options page, in tabs by use: the
	 * overview (bytes saved, statuses, libraries), the
	 * optimisation of the media library, the delivery
	 * on the site, the settings and the maintenance.
	 * All tabs share one form, so saving never resets
	 * the hidden tabs; without JS they are shown one
	 * after another.
	 *
	 * @since 1.0.0
	 * @date 25/10/2026
	 */
	public function render_page()
	{
		if (!current_user_can('manage_options')) {
			return;
		}
		// Saving the settings reads the versions of the libraries again, e.g. after an upgrade.
		if (isset($_GET['settings-updated'])) {
			Service::forget_versions();
		}
		$sections = self::sections();
		$formats = self::formats($sections);
		$counts = Status::counts();
		$jobs = class_exists('OIO_Vendor_WP_Background_Process');
		$tabs = [
			'overview' => 'Overview',
			'optimise' => 'Optimise',
			'delivery' => 'Delivery',
			'settings' => 'Settings',
			'maintenance' => 'Maintenance',
		];
		// The settings tab warns when a format has nothing to make it.
		$missing = in_array('off', array_map([self::class, 'state'], $formats), true);
		?>
		<div class="wrap oio-page">
			<header class="oio-header">
				<img class="oio-header__logo" src="<?php echo esc_url(OIO_URL . 'assets/icon-128x128.png'); ?>" alt="" width="64" height="64">
				<div class="oio-header__text">
					<h1 class="oio-header__title">OIO <span class="oio-header__name">Octopus Image Optimizer</span></h1>
					<p class="oio-header__tagline">Squeezes images. Spills no ink, sends no bill.</p>
				</div>
				<span class="oio-header__version">v<?php echo esc_html(OIO_VERSION); ?></span>
			</header>
			<?php // WordPress puts its notices after the first heading of the page, or this marker. ?>
			<hr class="wp-header-end">

			<nav class="oio-tabs" role="tablist" aria-label="OIO">
				<?php foreach ($tabs as $id => $title) : ?>
					<a href="#<?php echo esc_attr($id); ?>" class="oio-tabs__tab" role="tab" data-tab="<?php echo esc_attr($id); ?>">
						<?php echo esc_html($title); ?>
						<?php if ($id === 'settings' && $missing) : ?>
							<span class="oio-dot oio-dot--off" title="A format has no library"></span>
						<?php endif; ?>
					</a>
				<?php endforeach; ?>
			</nav>

			<?php if ($jobs) : ?>
				<?php $this->render_job(); ?>
			<?php endif; ?>

			<form class="oio-page__form" action="options.php" method="post">
				<?php settings_fields(self::PAGE); ?>

				<section class="oio-panel" id="oio-panel-overview" role="tabpanel" data-panel="overview">
					<?php $this->render_overview($formats, $counts); ?>
				</section>

				<section class="oio-panel" id="oio-panel-optimise" role="tabpanel" data-panel="optimise">
					<?php $this->render_optimise($counts, $jobs); ?>
				</section>

				<section class="oio-panel" id="oio-panel-delivery" role="tabpanel" data-panel="delivery">
					<?php $this->render_delivery($sections['display'], $formats, $counts); ?>
				</section>

				<section class="oio-panel" id="oio-panel-settings" role="tabpanel" data-panel="settings">
					<?php $this->render_settings($sections['general'], $formats); ?>
				</section>

				<section class="oio-panel" id="oio-panel-maintenance" role="tabpanel" data-panel="maintenance">
					<?php $this->render_maintenance($jobs); ?>
				</section>
			</form>
		</div>
		<?php
	}

	/**
	 * Returns the format sections (JPG, PNG, WebP,
	 * AVIF) with the state of their library: installed,
	 * version, the engine making the format (the
	 * library, or GD or Imagick for WebP and AVIF
	 * without it, null if none) and the optional
	 * libraries (e.g. pngquant).
	 *
	 * @param array $sections
	 * @return array
	 * @since 1.0.0
	 * @date 25/10/2026
	 */
	private static function formats($sections)
	{
		$formats = [];
		foreach ($sections as $id => $section) {
			if (empty($section['service'])) {
				continue;
			}
			$service = $section['service'];
			$section['installed'] = $service::installed();
			$section['version'] = $service::version_number();
			$section['enabled'] = self::get('oio_' . $id . '_enable');
			$section['library'] = $service::library();
			$section['engine'] = $service::engine();
			$section['optional'] = isset($section['optional']) ? $section['optional'] : [];
			$section['extras'] = [];
			foreach (array_keys($section['optional']) as $name) {
				$section['extras'][$name] = $service::installed($name);
			}
			$formats[$id] = $section;
		}
		return $formats;
	}

	/**
	 * Renders the overview: the bytes saved, the images
	 * by status and the state of each format.
	 *
	 * @param array $formats
	 * @param array $counts
	 * @since 1.0.0
	 * @date 25/10/2026
	 */
	private function render_overview($formats, $counts)
	{
		$savings = self::savings();
		?>
		<div class="oio-card oio-card--hero">
			<div class="oio-savings">
				<div class="oio-savings__main"<?php echo $savings['images'] ? '' : ' hidden'; ?>>
					<p class="oio-savings__value"><span data-savings="saved"><?php echo esc_html($savings['saved']); ?></span> saved</p>
					<p class="oio-savings__detail">
						<span class="oio-savings__reduction" data-savings="reduction"><?php echo esc_html($savings['reduction']); ?></span>
						on what the site serves
						<span class="oio-savings__hint"<?php echo $savings['hint'] ? '' : ' hidden'; ?>>
							&middot; <a href="#delivery" data-tab="delivery"><span data-savings="modern"><?php echo esc_html($savings['modern']); ?></span> with WebP and AVIF served</a>
						</span>
					</p>
					<p class="oio-savings__disk">
						Disk used by OIO: originals <span data-savings="originals"><?php echo esc_html($savings['originals']); ?></span>,
						WebP and AVIF <span data-savings="formats"><?php echo esc_html($savings['formats']); ?></span>
					</p>
				</div>
				<div class="oio-savings__empty"<?php echo $savings['images'] ? ' hidden' : ''; ?>>
					<p class="oio-savings__value">Ready to squeeze</p>
					<p class="oio-savings__detail">OIO optimises your images on your own server: free, unlimited, and they never leave it.</p>
				</div>
			</div>
			<ul class="oio-counts">
				<?php foreach (Media::LABELS as $status => $label) : ?>
					<li class="oio-counts__item oio-counts__item--<?php echo esc_attr($status); ?>">
						<span class="oio-counts__value" data-count="<?php echo esc_attr($status); ?>"><?php echo esc_html(number_format_i18n($counts[$status])); ?></span>
						<span class="oio-counts__label"><?php echo esc_html($label); ?></span>
					</li>
				<?php endforeach; ?>
			</ul>
			<p class="oio-card__footer">
				<?php echo esc_html(sprintf(_n('%s JPG or PNG image in the media library.', '%s JPG and PNG images in the media library.', $counts['total']), number_format_i18n($counts['total']))); ?>
				<a href="#optimise" data-tab="optimise">Optimise the media library</a>
			</p>
		</div>

		<h2 class="oio-heading">Formats</h2>
		<div class="oio-libraries">
			<?php foreach ($formats as $id => $format) : ?>
				<?php $state = self::state($format); ?>
				<a class="oio-library oio-library--<?php echo esc_attr($state); ?>" href="#settings-<?php echo esc_attr($id); ?>" data-tab="settings" data-target="oio-settings-<?php echo esc_attr($id); ?>">
					<span class="oio-library__head">
						<span class="oio-library__format"><?php echo esc_html($format['title']); ?></span>
						<?php $this->render_badge($state, self::STATE_LABELS[$state]); ?>
					</span>
					<?php $this->render_engine($format); ?>
				</a>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/**
	 * Renders the engine of a format: its library and
	 * version, or GD or Imagick without the library,
	 * then its optional libraries (e.g. pngquant).
	 *
	 * @param array $format
	 * @since 1.0.0
	 * @date 25/10/2026
	 */
	private function render_engine($format)
	{
		if ($format['installed']) {
			$name = $format['library'];
			$state = 'installed';
		} elseif ($format['engine']) {
			$name = $format['engine'];
			$state = $format['library'] . ' not installed';
		} else {
			$name = $format['library'];
			$state = 'not installed';
		}
		?>
		<span class="oio-engine oio-engine--<?php echo $format['engine'] ? 'on' : 'off'; ?>">
			<span class="oio-engine__name"><?php echo esc_html($name); ?></span>
			<span class="oio-engine__state"><?php echo esc_html($state); ?></span>
			<?php if ($format['version']) : ?>
				<span class="oio-engine__version"><?php echo esc_html($format['version']); ?></span>
			<?php endif; ?>
		</span>
		<?php foreach ($format['extras'] as $extra => $installed) : ?>
			<span class="oio-engine oio-engine--<?php echo $installed ? 'on' : 'off'; ?>">
				<span class="oio-engine__name"><?php echo esc_html($extra); ?></span>
				<span class="oio-engine__state"><?php echo $installed ? 'installed' : 'not installed'; ?></span>
			</span>
		<?php endforeach; ?>
		<?php
	}

	/**
	 * Renders the optimisation of the media library:
	 * the new images, the outdated ones, or all.
	 *
	 * @param array $counts
	 * @param bool $jobs
	 * @since 1.0.0
	 * @date 25/10/2026
	 */
	private function render_optimise($counts, $jobs)
	{
		?>
		<div class="oio-card">
			<header class="oio-card__header">
				<span class="oio-card__icon dashicons dashicons-images-alt2" aria-hidden="true"></span>
				<div>
					<h2 class="oio-card__title">Optimise the media library</h2>
					<p class="oio-card__description">These jobs run in the background, page closed or not, with the lowest priority on the server.</p>
				</div>
			</header>
			<?php if ($jobs) : ?>
				<div class="oio-actions">
					<div class="oio-action oio-action--primary">
						<span class="oio-action__icon dashicons dashicons-format-image" aria-hidden="true"></span>
						<h3 class="oio-action__title">New images</h3>
						<p class="oio-action__count" data-count="new"><?php echo esc_html(number_format_i18n($counts[Status::TO_OPTIMISE] + $counts[Status::ERROR])); ?></p>
						<p class="oio-action__text">Not optimised yet, or their optimisation failed.</p>
						<button type="button" class="button button-primary oio-action__button" data-job="new">Optimise new images</button>
					</div>
					<div class="oio-action">
						<span class="oio-action__icon dashicons dashicons-update" aria-hidden="true"></span>
						<h3 class="oio-action__title">Outdated images</h3>
						<p class="oio-action__count" data-count="outdated"><?php echo esc_html(number_format_i18n($counts[Status::OUTDATED])); ?></p>
						<p class="oio-action__text">Optimised before a quality, a format, the maximum size or the thumbnail sizes changed. Optimised again from their originals.</p>
						<button type="button" class="button oio-action__button" data-job="outdated">Re-optimise outdated images</button>
					</div>
					<div class="oio-action">
						<span class="oio-action__icon dashicons dashicons-images-alt" aria-hidden="true"></span>
						<h3 class="oio-action__title">All images</h3>
						<p class="oio-action__count" data-count="total"><?php echo esc_html(number_format_i18n($counts['total'])); ?></p>
						<p class="oio-action__text">Every image optimised again from its original, with its thumbnails made again.</p>
						<button type="button" class="button oio-action__button" data-job="all" data-confirm="Re-optimise all images from their originals, with their thumbnails? This can take a while.">Re-optimise all images</button>
					</div>
				</div>
			<?php else : ?>
				<?php $this->render_no_jobs(); ?>
			<?php endif; ?>
			<p class="oio-card__footer">
				New uploads are optimised right away.
				<a href="<?php echo esc_url(admin_url('upload.php?mode=list')); ?>">See each image in the Media library</a>
			</p>
		</div>
		<?php
	}

	/**
	 * Renders the delivery of the WebP and AVIF files,
	 * in three steps side by side: generate them (how
	 * many images have them), serve them (in the HTML or
	 * from the server) and test what the server sends.
	 * The results of the test come below, then the rules
	 * of the server and the theme helper, folded.
	 *
	 * @param array $display
	 * @param array $formats
	 * @param array $counts
	 * @since 1.0.0
	 * @date 25/10/2026
	 */
	private function render_delivery($display, $formats, $counts)
	{
		$server = Delivery::server();
		$names = ['apache' => 'Apache', 'nginx' => 'nginx', 'other' => 'Your server'];
		$made = self::with_formats();
		$total = $counts['total'];
		$percent = $total ? min(100, (int)round($made / $total * 100)) : 0;
		$rewrite = $display['fields'][Delivery::OPTION];
		if ($server !== 'apache') {
			// OIO cannot write the rules: the option tells they are in place.
			$rewrite['note'] = sprintf('%s: add the rules below to its configuration, then tick this box.', esc_html($names[$server]));
			$rewrite['help'] = sprintf('%s does not read <code>.htaccess</code> files: add the rules below to its configuration, then tick this box so that OIO counts the bytes saved. The HTML does not change, and every image is served, CSS backgrounds and custom themes included.', esc_html($names[$server]));
		}
		?>
		<ol class="oio-steps">
			<li class="oio-step">
				<header class="oio-step__header">
					<span class="oio-step__number" aria-hidden="true">1</span>
					<div class="oio-step__heading">
						<h2 class="oio-step__title">Generate</h2>
						<p class="oio-step__description">The WebP and AVIF versions are made while the images are optimised.</p>
					</div>
				</header>
				<div class="oio-step__body oio-generate">
					<p class="oio-generate__value">
						<strong><?php echo esc_html(number_format_i18n($made)); ?></strong>
						<?php echo esc_html(sprintf(_n('of %s image has its WebP or AVIF version', 'of %s images have their WebP or AVIF version', $total), number_format_i18n($total))); ?>
					</p>
					<span class="oio-meter" role="img" aria-label="<?php echo esc_attr($percent . ' %'); ?>"><span class="oio-meter__value" style="width: <?php echo esc_attr($percent); ?>%"></span></span>
					<ul class="oio-generate__formats">
						<?php foreach (['webp', 'avif'] as $id) : ?>
							<?php $state = self::state($formats[$id]); ?>
							<li>
								<a class="oio-chip" href="#settings-<?php echo esc_attr($id); ?>" data-tab="settings" data-target="oio-settings-<?php echo esc_attr($id); ?>">
									<span class="oio-dot oio-dot--<?php echo esc_attr($state); ?>" aria-hidden="true"></span>
									<span class="oio-chip__name"><?php echo esc_html($formats[$id]['title']); ?></span>
									<span class="oio-chip__state"><?php echo esc_html(self::STATE_LABELS[$state]); ?></span>
								</a>
							</li>
						<?php endforeach; ?>
					</ul>
					<?php if ($made < $total) : ?>
						<a class="button oio-generate__button" href="#optimise" data-tab="optimise">Optimise the media library</a>
					<?php endif; ?>
				</div>
			</li>

			<li class="oio-step oio-step--wide">
				<header class="oio-step__header">
					<span class="oio-step__number" aria-hidden="true">2</span>
					<div class="oio-step__heading">
						<h2 class="oio-step__title"><?php echo esc_html($display['title']); ?></h2>
						<p class="oio-step__description"><?php echo esc_html($display['description']); ?></p>
					</div>
				</header>
				<div class="oio-step__body oio-methods">
					<div class="oio-method">
						<?php $this->render_field('oio_display_formats', $display['fields']['oio_display_formats']); ?>
					</div>
					<div class="oio-method">
						<?php $this->render_field(Delivery::OPTION, $rewrite); ?>
					</div>
				</div>
				<footer class="oio-step__actions">
					<?php submit_button('Save changes', 'primary', 'submit', false, ['id' => 'oio-save-delivery']); ?>
				</footer>
			</li>

			<li class="oio-step oio-test">
				<header class="oio-step__header">
					<span class="oio-step__number" aria-hidden="true">3</span>
					<div class="oio-step__heading">
						<h2 class="oio-step__title">Test</h2>
						<p class="oio-step__description">Requests an optimised image as a browser, after saving.</p>
					</div>
				</header>
				<div class="oio-step__body">
					<ul class="oio-test__list">
						<li>Are the WebP and AVIF files served with their type?</li>
						<li>Does the server answer the image with them?</li>
						<li>Are the originals kept private?</li>
					</ul>
					<button type="button" class="button button-primary oio-test__button" data-delivery-test>Run the test</button>
					<p class="oio-test__summary" aria-live="polite" hidden></p>
				</div>
			</li>
		</ol>

		<div class="oio-card oio-test__result" hidden>
			<header class="oio-card__header">
				<span class="oio-card__icon dashicons dashicons-yes-alt" aria-hidden="true"></span>
				<div>
					<h2 class="oio-card__title">Test results</h2>
					<p class="oio-card__description oio-test__sample"></p>
				</div>
			</header>
			<div class="oio-card__body">
				<ul class="oio-checks"></ul>
			</div>
		</div>

		<h2 class="oio-heading">Rules and code</h2>
		<?php if ($server === 'apache') : ?>
			<?php $written = Delivery::rules_written(); ?>
			<details class="oio-fold">
				<summary class="oio-fold__summary">
					<span class="oio-fold__title">Rewrite rules for Apache and LiteSpeed</span>
					<?php $this->render_badge($written ? 'on' : 'muted', $written ? 'Written in uploads/.htaccess' : 'Not written'); ?>
				</summary>
				<div class="oio-fold__content">
					<p class="oio-fold__text">OIO writes them in <code>uploads/.htaccess</code> when the server option is on, and removes them when it is off.</p>
					<pre class="oio-code"><code><?php echo esc_html(implode("\n", Delivery::apache_rules())); ?></code></pre>
				</div>
			</details>
		<?php else : ?>
			<?php if ($server === 'other') : ?>
				<details class="oio-fold">
					<summary class="oio-fold__summary"><span class="oio-fold__title">Rewrite rules for Apache and LiteSpeed</span></summary>
					<div class="oio-fold__content">
						<p class="oio-fold__text">In <code>uploads/.htaccess</code>, written by OIO when the server option is on.</p>
						<pre class="oio-code"><code><?php echo esc_html(implode("\n", Delivery::apache_rules())); ?></code></pre>
					</div>
				</details>
			<?php endif; ?>
			<details class="oio-fold">
				<summary class="oio-fold__summary"><span class="oio-fold__title">Rewrite rules for nginx</span></summary>
				<div class="oio-fold__content">
					<p class="oio-fold__text">Add them to the configuration of the site, reload nginx, then tick the server option.</p>
					<pre class="oio-code"><code><?php echo esc_html(Delivery::nginx_rules()); ?></code></pre>
				</div>
			</details>
		<?php endif; ?>
		<?php $this->render_theme_help(); ?>
		<?php
	}

	/**
	 * Returns the number of images with a WebP or an
	 * AVIF version, for the first step of the delivery.
	 *
	 * @return int
	 * @since 1.0.0
	 * @date 26/10/2026
	 */
	private static function with_formats()
	{
		global $wpdb;
		return (int)$wpdb->get_var($wpdb->prepare(
			"SELECT COUNT(DISTINCT m.post_id) FROM {$wpdb->postmeta} m
			INNER JOIN {$wpdb->posts} p ON p.ID = m.post_id
			WHERE m.meta_key IN (%s, %s)
			AND p.post_type = 'attachment' AND p.post_status = 'inherit'
			AND p.post_mime_type IN (" . implode(', ', array_fill(0, count(Status::MIMES), '%s')) . ")",
			array_merge([WebP::meta_key(), AVIF::meta_key()], Status::MIMES)
		));
	}

	/**
	 * Renders the settings: the image size, then one
	 * compact card per format, two per row on wide
	 * screens: its name, state and switch, the library
	 * making it, its quality fields, and how to install
	 * a missing library, folded.
	 *
	 * @param array $general
	 * @param array $formats
	 * @since 1.0.0
	 * @date 25/10/2026
	 */
	private function render_settings($general, $formats)
	{
		?>
		<div class="oio-card" id="oio-settings-general">
			<header class="oio-card__header">
				<span class="oio-card__icon dashicons dashicons-image-crop" aria-hidden="true"></span>
				<div>
					<h2 class="oio-card__title"><?php echo esc_html($general['title']); ?></h2>
					<p class="oio-card__description"><?php echo esc_html($general['description']); ?></p>
				</div>
			</header>
			<div class="oio-card__body oio-fields">
				<?php foreach ($general['fields'] as $name => $field) : ?>
					<?php $this->render_field($name, $field); ?>
				<?php endforeach; ?>
			</div>
		</div>

		<div class="oio-formats">
			<?php foreach ($formats as $id => $format) : ?>
				<?php
				$state = self::state($format);
				$switch = 'oio_' . $id . '_enable';
				$fields = $format['fields'];
				unset($fields[$switch]);
				?>
				<section class="oio-format oio-format--<?php echo esc_attr($state); ?>" id="oio-settings-<?php echo esc_attr($id); ?>">
					<header class="oio-format__header">
						<div class="oio-format__top">
							<h2 class="oio-format__title"><?php echo esc_html($format['title']); ?></h2>
							<?php $this->render_badge($state, self::STATE_LABELS[$state]); ?>
							<?php $this->render_switch($switch, $format['fields'][$switch]); ?>
						</div>
						<div class="oio-format__engines">
							<?php $this->render_engine($format); ?>
						</div>
					</header>
					<p class="oio-format__description"><?php echo wp_kses($format['fields'][$switch]['help'], ['strong' => [], 'code' => []]); ?></p>
					<?php if (!$format['installed'] && $format['engine']) : ?>
						<details class="oio-install oio-install--advice">
							<summary class="oio-install__summary">Recommended: install <?php echo esc_html($format['library']); ?></summary>
							<p class="oio-install__text"><?php echo esc_html(sprintf($format['recommendation'], $format['engine'])); ?></p>
							<?php echo $this->render_template('health' . DIRECTORY_SEPARATOR . $format['template']); ?>
						</details>
					<?php elseif (!$format['installed']) : ?>
						<details class="oio-install">
							<summary class="oio-install__summary">How to install <?php echo esc_html($format['library']); ?></summary>
							<?php echo $this->render_template('health' . DIRECTORY_SEPARATOR . $format['template']); ?>
						</details>
					<?php endif; ?>
					<details class="oio-format__settings">
						<summary class="oio-format__summary">Settings</summary>
						<div class="oio-format__fields oio-fields">
							<?php foreach ($fields as $name => $field) : ?>
								<?php $this->render_field($name, $field); ?>
							<?php endforeach; ?>
							<?php foreach ($format['optional'] as $name => $use) : ?>
								<?php if ($format['installed'] && !$format['extras'][$name]) : ?>
									<details class="oio-install oio-install--advice">
										<summary class="oio-install__summary">How to install <?php echo esc_html($name); ?>, for the <?php echo esc_html($use); ?></summary>
										<?php echo $this->render_template('health' . DIRECTORY_SEPARATOR . $format['template']); ?>
									</details>
								<?php endif; ?>
							<?php endforeach; ?>
						</div>
					</details>
				</section>
			<?php endforeach; ?>
		</div>

		<div class="oio-page__save">
			<?php submit_button('Save changes', 'primary', 'submit', false, ['id' => 'oio-save-settings']); ?>
		</div>
		<?php
	}

	/**
	 * Renders the maintenance: removing the WebP and
	 * AVIF files, restoring the original images. Each
	 * asks to tick a box first.
	 *
	 * @param bool $jobs
	 * @since 1.0.0
	 * @date 25/10/2026
	 */
	private function render_maintenance($jobs)
	{
		?>
		<div class="oio-card">
			<header class="oio-card__header">
				<span class="oio-card__icon dashicons dashicons-trash" aria-hidden="true"></span>
				<div>
					<h2 class="oio-card__title">Remove WebP and AVIF</h2>
					<p class="oio-card__description">Deletes the WebP and AVIF files of the <strong data-count="processed"><?php echo esc_html(number_format_i18n(count(Cleaner::ids()))); ?></strong> images processed. The JPG and PNG files stay compressed, and the originals kept.</p>
				</div>
			</header>
			<?php if ($jobs) : ?>
				<div class="oio-confirm">
					<label class="oio-confirm__label"><input type="checkbox" class="oio-confirm__input" data-confirm-for="remove_formats"> I understand the WebP and AVIF files are deleted, until the images are optimised again.</label>
					<button type="button" class="button oio-confirm__button" data-job="remove_formats">Remove WebP and AVIF</button>
				</div>
			<?php else : ?>
				<?php $this->render_no_jobs(); ?>
			<?php endif; ?>
		</div>

		<div class="oio-card oio-card--danger">
			<header class="oio-card__header">
				<span class="oio-card__icon dashicons dashicons-backup" aria-hidden="true"></span>
				<div>
					<h2 class="oio-card__title">Restore original images</h2>
					<p class="oio-card__description">Puts the original images back and deletes every file made by OIO (WebP, AVIF, backups). Do it before deleting OIO: deleting it keeps the files as they are.</p>
				</div>
			</header>
			<?php if ($jobs) : ?>
				<div class="oio-confirm">
					<label class="oio-confirm__label"><input type="checkbox" class="oio-confirm__input" data-confirm-for="restore"> I understand the images are no longer optimised, and their WebP and AVIF files are deleted.</label>
					<button type="button" class="button oio-confirm__button oio-button--danger" data-job="restore">Restore original images</button>
				</div>
			<?php else : ?>
				<?php $this->render_no_jobs(); ?>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Renders why the jobs are not available: the
	 * dependencies of composer install are missing.
	 *
	 * @since 1.0.0
	 * @date 25/10/2026
	 */
	private function render_no_jobs()
	{
		?>
		<p class="oio-card__notice">The background jobs need the dependencies of OIO: run <code>composer install</code> in its folder. WP-CLI (<code>wp oio run</code>) works without them.</p>
		<?php
	}

	/**
	 * Renders the progress of the background job, shown
	 * on every tab while it runs, and its result.
	 *
	 * @since 1.0.0
	 * @date 25/10/2026
	 */
	private function render_job()
	{
		?>
		<div class="oio-job" hidden>
			<div class="oio-job__progress">
				<div class="oio-job__head">
					<span class="oio-job__icon dashicons dashicons-update" aria-hidden="true"></span>
					<p class="oio-job__label" aria-live="polite"></p>
					<span class="oio-job__percent"></span>
					<div class="oio-job__controls">
						<button type="button" class="button" data-control="pause">Pause</button>
						<button type="button" class="button" data-control="resume">Resume</button>
						<button type="button" class="button-link button-link-delete" data-control="cancel">Cancel</button>
					</div>
				</div>
				<progress class="oio-job__bar" max="100" value="0"></progress>
			</div>
			<div class="oio-result" role="status" aria-live="polite">
				<span class="oio-result__icon dashicons" aria-hidden="true"></span>
				<div class="oio-result__body">
					<p class="oio-result__title"></p>
					<p class="oio-result__detail"></p>
				</div>
				<button type="button" class="oio-result__close" data-control="dismiss">
					<span class="dashicons dashicons-no-alt" aria-hidden="true"></span>
					<span class="screen-reader-text">Dismiss</span>
				</button>
			</div>
		</div>
		<?php
	}

	/**
	 * Renders how to use oio_image() in a custom
	 * theme, whose images the display option does not
	 * change.
	 *
	 * @since 1.0.0
	 * @date 25/10/2026
	 */
	private function render_theme_help()
	{
		$code = "<?php\nif (function_exists('oio_image')) {\n\techo oio_image(\$image_id, 'my-class');\n} else {\n\techo wp_get_attachment_image(\$image_id, 'full');\n}";
		?>
		<details class="oio-fold">
			<summary class="oio-fold__summary"><span class="oio-fold__title">Custom themes: the <code>oio_image()</code> helper</span></summary>
			<div class="oio-fold__content">
				<p class="oio-fold__text">Images output with your own markup (e.g. from an ACF field) are not wrapped in a <code>&lt;picture&gt;</code> by the HTML option: serve them from the server, or use the <code>oio_image()</code> helper, which returns their <code>&lt;picture&gt;</code> whatever the options.</p>
				<pre class="oio-code"><code><?php echo esc_html($code); ?></code></pre>
				<p class="oio-fold__text">The second argument is the class of the <code>&lt;picture&gt;</code> element. Pass
					<code>true</code> as third argument to output <code>data-src</code> and <code>data-srcset</code> for a
					JavaScript lazy loader. Keep the <code>function_exists()</code> check: without it, the pages break while
					OIO is deactivated.</p>
			</div>
		</details>
		<?php
	}

	/**
	 * Returns the bytes saved on the media library, as
	 * shown on the overview: the reduction of what the
	 * site serves, the one it would get with the WebP
	 * and AVIF served, and the disk used by OIO. Sent
	 * again with the progress of a job (Tools::status()).
	 *
	 * @return array
	 * @since 1.0.0
	 * @date 25/10/2026
	 */
	public static function savings()
	{
		$totals = Stats::totals();
		$served = Stats::served();
		return [
			'images' => $totals['images'],
			'saved' => Stats::size(max(0, $totals['original'] - $totals[$served])),
			'reduction' => (string)Stats::reduction($totals, $served),
			'modern' => (string)Stats::reduction($totals, 'modern'),
			// Serving the WebP and AVIF would save more.
			'hint' => $served === 'compressed' && $totals['modern'] < $totals['compressed'],
			'originals' => Stats::size($totals['original']),
			'formats' => Stats::size($totals['extra']),
		];
	}

	/**
	 * Returns the state of a format, used for the
	 * dots and badges: on (enabled, and its library
	 * installed or the image editor making it), off
	 * (enabled but nothing can make it) or muted
	 * (disabled).
	 *
	 * @param array $format
	 * @return string
	 * @since 1.0.0
	 * @date 25/10/2026
	 */
	private static function state($format)
	{
		if (!$format['enabled']) {
			return 'muted';
		}
		return $format['engine'] ? 'on' : 'off';
	}

	/**
	 * Renders a status badge.
	 *
	 * @param string $variant on, off or muted.
	 * @param string $label
	 * @since 1.0.0
	 * @date 25/10/2026
	 */
	private function render_badge($variant, $label)
	{
		printf('<span class="oio-badge oio-badge--%s">%s</span>', esc_attr($variant), esc_html($label));
	}

	/**
	 * Renders an option field: a toggle for a checkbox,
	 * a label and its control otherwise.
	 *
	 * @param string $name
	 * @param array $field
	 * @since 1.0.0
	 * @date 25/10/2026
	 */
	private function render_field($name, $field)
	{
		$id = esc_attr(self::OPTION_PREFIX . $name);
		$value = self::get($name);
		// A sub-option, e.g. the lossy quality, shown under its checkbox while it is ticked (admin.js).
		$sub = empty($field['parent']) ? '' : ' oio-field--sub';
		$parent = empty($field['parent']) ? '' : sprintf(' data-parent="%s"', esc_attr(self::OPTION_PREFIX . $field['parent']));

		// The label names the control without being a <label>: hovering it would hover the control.
		printf('<div class="oio-field%s%s"%s><span class="oio-field__label" id="%s-label">%s</span>', $field['type'] === 'checkbox' ? ' oio-field--toggle' : '', $sub, $parent, $id, esc_html($field['label']));

		if ($field['type'] === 'checkbox') {
			printf(
				'<label class="oio-toggle"><input type="checkbox" class="oio-toggle__input" id="%s" name="%s" value="%s" aria-labelledby="%s-label" %s><span class="oio-toggle__track" aria-hidden="true"></span></label>',
				$id, $id, self::CHECKBOX_ON, $id, checked($value, true, false)
			);
		} else {
			echo '<div class="oio-field__control">';
			if ($field['type'] === 'number') {
				printf('<input type="number" class="oio-field__input" id="%s" name="%s" value="%s" min="%d" max="%d" step="1" aria-labelledby="%s-label">', $id, $id, esc_attr($value), $field['min'], $field['max'], $id);
			} elseif ($field['type'] === 'select') {
				printf('<select class="oio-field__input" id="%s" name="%s" aria-labelledby="%s-label">', $id, $id, $id);
				foreach ($field['options'] as $optionValue => $optionLabel) {
					printf('<option value="%s" %s>%s</option>', esc_attr($optionValue), selected((string)$value, (string)$optionValue, false), esc_html($optionLabel));
				}
				echo '</select>';
			}
			if (!empty($field['unit'])) {
				printf('<span class="oio-field__unit">%s</span>', esc_html($field['unit']));
			}
			echo '</div>';
		}

		$help = wp_kses($field['help'], ['strong' => [], 'code' => []]);
		if (empty($field['note'])) {
			printf('<p class="oio-field__help">%s</p></div>', $help);
			return;
		}
		// A short note, the full help behind a "?" button (admin.js hides it, shown without JS).
		printf(
			'<p class="oio-field__help">%s <button type="button" class="oio-help__toggle" aria-expanded="false" aria-controls="%s-help"><span aria-hidden="true">?</span><span class="screen-reader-text">More about: %s</span></button></p><p class="oio-field__help oio-help__text" id="%s-help">%s</p></div>',
			wp_kses($field['note'], ['strong' => [], 'code' => []]), $id, esc_html($field['label']), $id, $help
		);
	}

	/**
	 * Renders a checkbox option as a bare switch, for
	 * the header of a format card: its label is read by
	 * screen readers, its help shown apart.
	 *
	 * @param string $name
	 * @param array $field
	 * @since 1.0.0
	 * @date 26/10/2026
	 */
	private function render_switch($name, $field)
	{
		$id = esc_attr(self::OPTION_PREFIX . $name);
		printf(
			'<label class="oio-toggle oio-toggle--switch"><input type="checkbox" class="oio-toggle__input" id="%s" name="%s" value="%s" %s><span class="oio-toggle__track" aria-hidden="true"></span><span class="screen-reader-text">%s</span></label>',
			$id, $id, self::CHECKBOX_ON, checked(self::get($name), true, false), esc_html($field['label'])
		);
	}

	/**
	 * Sanitizes an option value before it is saved.
	 *
	 * @param mixed $value
	 * @param array $field
	 * @return string
	 * @since 1.0.0
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
	 * @since 1.0.0
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
					'oio_jpg_enable' => [
						'type' => 'checkbox',
						'label' => 'Compress JPG images',
						'default' => true,
						'help' => 'Compresses the JPG images in place with jpegoptim, and makes them progressive. Their originals are kept.',
					],
					'oio_jpg_quality' => [
						'type' => 'number',
						'label' => 'Quality',
						'min' => 0,
						'max' => 100,
						'default' => 80,
						'help' => 'From 0 to 100, 80 by default.',
					],
				],
			],
			'png' => [
				'title' => 'PNG',
				'service' => PNG::class,
				'template' => 'png.php',
				'optional' => ['pngquant' => 'lossy compression'],
				'fields' => [
					'oio_png_enable' => [
						'type' => 'checkbox',
						'label' => 'Compress PNG images',
						'default' => true,
						'help' => 'Compresses the PNG images in place without loss, with oxipng or optipng. Their originals are kept.',
					],
					'oio_png_quality' => [
						'type' => 'select',
						'label' => 'Optimisation level',
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
						'help' => 'From o0 (fastest) to o7 (smallest files), o2 by default.',
					],
					'oio_png_lossy' => [
						'type' => 'checkbox',
						'label' => 'Lossy compression (pngquant)',
						'default' => false,
						'help' => 'Often 60 to 80 % lighter, for graphics and screenshots. Needs pngquant.',
					],
					'oio_png_lossy_quality' => [
						'type' => 'number',
						'label' => 'Lossy quality',
						'min' => 0,
						'max' => 100,
						'default' => 80,
						'parent' => 'oio_png_lossy',
						'help' => 'The quality pngquant aims at, from 0 to 100, 80 by default.',
					],
				],
			],
			'webp' => [
				'title' => 'WebP',
				'service' => WebP::class,
				'template' => 'webp.php',
				'recommendation' => '%s makes the WebP files for now, in the PHP process. cwebp runs apart from PHP, and makes the PNG graphics and screenshots much lighter.',
				'fields' => [
					'oio_webp_enable' => [
						'type' => 'checkbox',
						'label' => 'Make WebP versions',
						'default' => true,
						'help' => 'Makes a WebP version of each image and thumbnail (<code>photo.jpg.webp</code>), lighter than the JPG or PNG.',
					],
					'oio_webp_quality' => [
						'type' => 'number',
						'label' => 'Quality',
						'min' => 0,
						'max' => 100,
						'default' => 80,
						'help' => 'From 0 to 100, 80 by default.',
					],
				],
			],
			'avif' => [
				'title' => 'AVIF',
				'service' => AVIF::class,
				'template' => 'avif.php',
				'recommendation' => '%s makes the AVIF files for now, in the PHP process. avifenc runs apart from PHP, on several threads, and keeps the colours of the PNG images sharper.',
				'fields' => [
					'oio_avif_enable' => [
						'type' => 'checkbox',
						'label' => 'Make AVIF versions',
						'default' => true,
						'help' => 'Makes an AVIF version of each image and thumbnail (<code>photo.jpg.avif</code>), often the lightest. Kept only when lighter than the WebP and the JPG or PNG.',
					],
					'oio_avif_quality' => [
						'type' => 'number',
						'label' => 'Quality',
						'min' => 0,
						'max' => 100,
						'default' => 70,
						'help' => 'From 0 to 100 (lossless), 70 by default.',
					],
				],
			],
			'general' => [
				'title' => 'Image size',
				'description' => 'WordPress scales the big images down on upload, and keeps the image uploaded as their original.',
				'fields' => [
					'oio_max_size' => [
						'type' => 'number',
						'label' => 'Maximum image size',
						'min' => 0,
						'max' => 20000,
						'unit' => 'px',
						'default' => 2560,
						'help' => 'Longest side, 2560 by default. <strong>0 means no limit: huge images can exhaust the server.</strong>',
					],
				],
			],
			'display' => [
				'title' => 'Serve',
				'description' => 'Each browser gets the lightest version it reads: AVIF, then WebP, or the JPG or PNG for older ones. Choose one way, or both.',
				'fields' => [
					'oio_display_formats' => [
						'type' => 'checkbox',
						'label' => 'In the HTML, with <picture>',
						'default' => false,
						'note' => 'Wraps the images of the content in a <code>&lt;picture&gt;</code>. Untick it if the layout breaks.',
						'help' => 'Images of the post content, featured images and galleries are wrapped in a <code>&lt;picture&gt;</code> tag offering the AVIF and WebP versions. Works on every server. <strong>Untick this if your theme layout breaks around images.</strong>',
					],
					Delivery::OPTION => [
						'type' => 'checkbox',
						'label' => 'From the server, with rewrite rules',
						'default' => false,
						'note' => 'Every image, CSS backgrounds included, the HTML unchanged.',
						'help' => 'The server answers <code>photo.jpg</code> with <code>photo.jpg.avif</code> or <code>.webp</code> when the browser reads them. The HTML does not change, and every image is served, CSS backgrounds and custom themes included. A CDN must vary its cache on the <code>Accept</code> header.',
					],
				],
			],
		];
	}

	/**
	 * Renders an OIO template in the /templates
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
		include OIO_TEMPLATE_PATH . DIRECTORY_SEPARATOR . $name;
		return ob_get_clean();
	}
}
