<?php

/**
 * SettingsPage
 *
 * The page of OIO, in the Settings menu: its tabs,
 * and the fields of the settings it saves (see
 * Octopus\Settings).
 *
 * @package     OIO
 * @author      Clément Gayot
 * @author      Ainsley Clark (Squidge, original author)
 * @category    Class
 * @repo        https://github.com/Striffly/octopus-image-optimizer
 *
 */

namespace Octopus\Admin;

use Octopus\Delivery\Htaccess;
use Octopus\Encoders\AVIF;
use Octopus\Encoders\JPG;
use Octopus\Encoders\PNG;
use Octopus\Encoders\WebP;
use Octopus\Engines\Libraries;
use Octopus\Media\Cleaner;
use Octopus\Media\Files;
use Octopus\Media\Stats;
use Octopus\Media\Status;
use Octopus\Plugin;
use Octopus\Settings;

if (!defined('ABSPATH')) {
	exit; // Exit if accessed directly
}

class SettingsPage
{
	/**
	 * The options page slug.
	 *
	 * @var string
	 */
	const PAGE = 'octopus-image-optimizer';

	/**
	 * Where to buy a coffee to the author: PayPal (5 €
	 * suggested) or Bitcoin.
	 *
	 * @var string
	 */
	const PAYPAL = 'https://paypal.me/striffly/5EUR';
	const BITCOIN = 'bc1q3j7gxxykpu9zp3sgxyallw0hq0sr659zl35wre';

	/**
	 * The stylesheets of the page, in their cascade
	 * order: base/ what every tab shares, tabs/ one tab
	 * each.
	 *
	 * @var string[]
	 */
	const STYLES = [
		'base/tokens',
		'base/layout',
		'base/wordpress',
		'base/components',
		'base/fields',
		'base/jobs',
		'tabs/overview',
		'tabs/optimise',
		'tabs/delivery',
		'tabs/settings',
	];

	/**
	 * The scripts of the page: each class after the
	 * ones it uses, then settings.js, which starts them.
	 *
	 * @var string[]
	 */
	const SCRIPTS = [
		'utils/Api',
		'utils/Confetti',
		'utils/Roll',
		'views/Screen',
		'components/Component',
		'components/Tabs',
		'components/Form',
		'components/HelpToggle',
		'components/DependentField',
		'components/CopyButton',
		'components/Wallet',
		'components/Count',
		'components/Job',
		'components/JobStart',
		'components/Savings',
		'components/Cta',
		'components/AllOptimised',
		'components/DeliveryTest',
		'settings',
	];

	/**
	 * Registers the options page.
	 *
	 * @since 1.0.0
	 */
	public function __construct()
	{
		add_action('admin_menu', [$this, 'add_page']);
		add_action('admin_enqueue_scripts', [$this, 'styles_and_scripts']);
	}

	/**
	 * Returns the URL of the options page.
	 *
	 * @return string
	 * @since 1.0.0
	 */
	public static function page_url()
	{
		return admin_url('options-general.php?page=' . self::PAGE);
	}

	/**
	 * Registers custom admin style and script.
	 *
	 * @param string $hook
	 * @since 1.0.0
	 */
	public function styles_and_scripts($hook)
	{
		if ($hook !== 'settings_page_' . self::PAGE) {
			return;
		}
		Plugin::enqueue_chain(self::STYLES, 'style');
		$script = Plugin::enqueue_chain(self::SCRIPTS, 'script');
		wp_localize_script($script, 'octopusSettings', [
			'ajaxurl' => admin_url('admin-ajax.php'),
			'nonce' => wp_create_nonce(JobsAjax::NONCE),
		]);
		add_action('admin_head', [$this, 'preload_font']);
	}

	/**
	 * Preloads the display font (tokens.css), so that
	 * the titles and the figures do not show in another
	 * font first, then move once it is loaded.
	 *
	 * @since 1.0.0
	 */
	public function preload_font()
	{
		printf('<link rel="preload" href="%s" as="font" type="font/woff2" crossorigin>' . "\n", esc_url(OCTOPUS_URL . 'assets/fonts/unbounded-variable.woff2'));
	}

	/**
	 * Adds the options page under Settings, and a link
	 * to its optimise tab under Media, where the other
	 * optimizers put their bulk optimisation. Without a
	 * callback, WordPress links the slug as it is.
	 *
	 * @since 1.0.0
	 */
	public function add_page()
	{
		$hook = add_options_page('OIO – Octopus Image Optimizer', 'Octopus Image Optimizer', 'manage_options', self::PAGE, [$this, 'render_page']);
		// Before the header, which shows the errors of the settings.
		add_action('load-' . $hook, [Htaccess::class, 'repair']);
		add_media_page('OIO – Octopus Image Optimizer', __('Optimise images', 'octopus-image-optimizer'), 'manage_options', 'options-general.php?page=' . self::PAGE . '#optimise');
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
	 */
	public function render_page()
	{
		if (!current_user_can('manage_options')) {
			return;
		}
		$sections = self::sections();
		$formats = self::formats($sections);
		$counts = Status::counts();
		$jobs = class_exists('Octopus_Vendor_WP_Background_Process');
		$tabs = [
			'overview' => __('Overview', 'octopus-image-optimizer'),
			'optimise' => __('Optimise', 'octopus-image-optimizer'),
			'delivery' => __('Delivery', 'octopus-image-optimizer'),
			'settings' => __('Settings', 'octopus-image-optimizer'),
			'maintenance' => __('Maintenance', 'octopus-image-optimizer'),
		];
		// The settings tab warns when a format has nothing to make it.
		$missing = in_array('off', array_map([self::class, 'state'], $formats), true);
		?>
		<div class="wrap octopus-page js-octopus-page">
			<header class="octopus-header">
				<?php // The octopus floats, unless the system asks for less motion. Loaded early, as it opens the page. ?>
				<picture class="octopus-header__picture">
					<source srcset="<?php echo esc_url(OCTOPUS_URL . 'assets/images/icon-128x128.png'); ?> 1x, <?php echo esc_url(OCTOPUS_URL . 'assets/images/icon-256x256.png'); ?> 2x" media="(prefers-reduced-motion: reduce)">
					<img class="octopus-header__logo" src="<?php echo esc_url(OCTOPUS_URL . 'assets/images/icon-128x128.gif'); ?>" srcset="<?php echo esc_url(OCTOPUS_URL . 'assets/images/icon-256x256.gif'); ?> 2x" alt="" width="80" height="80" fetchpriority="high">
				</picture>
				<div class="octopus-header__text">
					<h1 class="octopus-header__title">OIO <span class="octopus-header__name">Octopus Image Optimizer</span></h1>
					<?php // The tagline stays in English, as the name of OIO. ?>
					<p class="octopus-header__tagline">Squeezes images. Spills no ink, sends no bill.</p>
				</div>
				<span class="octopus-header__version">v<?php echo esc_html(OCTOPUS_VERSION); ?></span>
			</header>
			<?php // WordPress puts its notices after the first heading of the page, or this marker. ?>
			<hr class="wp-header-end">

			<nav class="octopus-tabs js-octopus-tabs" role="tablist" aria-label="OIO">
				<?php foreach ($tabs as $id => $title) : ?>
					<a href="#<?php echo esc_attr($id); ?>" class="octopus-tabs__tab js-octopus-tab js-octopus-tab-link" role="tab" data-tab="<?php echo esc_attr($id); ?>">
						<?php echo esc_html($title); ?>
						<?php if ($id === 'settings') : ?>
							<?php // Follows the settings as they are saved (Form.js). ?>
							<span class="octopus-tabs__dot js-octopus-live" id="octopus-tab-settings-dot">
								<?php if ($missing) : ?>
									<span class="octopus-dot octopus-dot--off" title="<?php esc_attr_e('A format has no library', 'octopus-image-optimizer'); ?>"></span>
								<?php endif; ?>
							</span>
						<?php endif; ?>
					</a>
				<?php endforeach; ?>
			</nav>
			<?php
			// Before the page paints, picks the tab as Tabs.js will, so that the other panels never show while the scripts load.
			wp_print_inline_script_tag(
				"(() => {
					const page = document.currentScript.closest('.js-octopus-page');
					const tabs = [...page.querySelectorAll('.js-octopus-tab')];
					let name = location.hash.slice(1);
					try {
						name ||= sessionStorage.getItem('octopus-tab');
					} catch {
						// Not kept.
					}
					if (!tabs.some((tab) => tab.dataset.tab === name)) {
						name = 'overview';
					}
					page.classList.add('octopus-page--js');
					page.dataset.tab = name;
					tabs.forEach((tab) => tab.classList.toggle('octopus-tabs__tab--active', tab.dataset.tab === name));
				})();"
			);
			?>

			<?php if ($jobs) : ?>
				<?php $this->render_job(); ?>
			<?php endif; ?>

			<form class="octopus-page__form js-octopus-form" action="options.php" method="post">
				<?php settings_fields(Settings::GROUP); ?>

				<section class="octopus-panel js-octopus-panel" id="octopus-panel-overview" role="tabpanel" data-panel="overview">
					<?php $this->render_overview($formats, $counts); ?>
				</section>

				<section class="octopus-panel js-octopus-panel" id="octopus-panel-optimise" role="tabpanel" data-panel="optimise">
					<?php $this->render_optimise($counts, $jobs); ?>
				</section>

				<section class="octopus-panel js-octopus-panel" id="octopus-panel-delivery" role="tabpanel" data-panel="delivery">
					<?php $this->render_delivery($sections['display'], $formats, $counts); ?>
				</section>

				<section class="octopus-panel js-octopus-panel" id="octopus-panel-settings" role="tabpanel" data-panel="settings">
					<?php $this->render_settings($sections['general'], $formats); ?>
				</section>

				<section class="octopus-panel js-octopus-panel" id="octopus-panel-maintenance" role="tabpanel" data-panel="maintenance">
					<?php $this->render_maintenance($jobs); ?>
				</section>
			</form>

			<?php $this->render_support(); ?>
			<span class="screen-reader-text js-octopus-announcer" aria-live="polite"></span>
		</div>
		<?php
	}

	/**
	 * Renders how to buy a coffee to the author, at the
	 * bottom of every tab: PayPal, or Bitcoin with its
	 * address and QR code, behind its button (Wallet.js
	 * hides them, shown without JS). No notice, nothing
	 * elsewhere in the admin but a link on the plugins
	 * page.
	 *
	 * @since 1.0.0
	 */
	private function render_support()
	{
		?>
		<footer class="octopus-support" id="octopus-support">
			<p class="octopus-support__links">
				<span aria-hidden="true">☕</span>
				<?php
				/* translators: Followed by the links to PayPal and Bitcoin. */
				esc_html_e('OIO is free, and will stay so. If it saves you time or bytes, you can buy me a coffee:', 'octopus-image-optimizer');
				?>
				<a href="<?php echo esc_url(self::PAYPAL); ?>" target="_blank" rel="noopener">PayPal</a>
				<span aria-hidden="true">&middot;</span>
				<button type="button" class="button-link octopus-support__toggle js-octopus-wallet-toggle" aria-expanded="true" aria-controls="octopus-support-wallet">Bitcoin</button>
			</p>
			<div class="octopus-support__wallet js-octopus-wallet" id="octopus-support-wallet">
				<a class="octopus-support__qr" href="<?php echo esc_url('bitcoin:' . self::BITCOIN, ['bitcoin']); ?>">
					<img src="<?php echo esc_url(OCTOPUS_URL . 'assets/images/bitcoin-qr.svg'); ?>" width="104" height="104" alt="<?php esc_attr_e('QR code of the Bitcoin address', 'octopus-image-optimizer'); ?>">
				</a>
				<?php $this->render_command(self::BITCOIN, 'octopus-support__address'); ?>
			</div>
		</footer>
		<?php
	}

	/**
	 * Returns the format sections (JPG, PNG, WebP,
	 * AVIF) with the state of their library: installed,
	 * version, the engine making the format (the
	 * library, or GD or Imagick for WebP and AVIF
	 * without it, null if none), the optional
	 * libraries (e.g. pngquant), and whether the
	 * better library replacing the one installed is
	 * missing (oxipng, for optipng).
	 *
	 * @param array $sections
	 * @return array
	 * @since 1.0.0
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
			$section['enabled'] = Settings::get($id . '_enable');
			$section['library'] = $service::library();
			$section['engine'] = $service::engine();
			$section['upgrade'] = $section['installed'] && isset($section['preferred']) && !$service::installed($section['preferred']);
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
	 */
	private function render_overview($formats, $counts)
	{
		$savings = self::savings();
		?>
		<?php if (!Libraries::can_execute()) : ?>
			<div class="notice notice-error inline octopus-alert">
				<p>
					<strong><?php esc_html_e('PHP cannot run programs on this server.', 'octopus-image-optimizer'); ?></strong>
					<?php
					$this->printf_html(
						/* translators: 1: proc_open(), 2: exec(), 3: disable_functions */
						__('%1$s and %2$s are disabled (%3$s): the libraries cannot run, even installed, so the JPG and PNG images are not compressed. Ask your host to enable %1$s. Meanwhile, GD or Imagick make the WebP and AVIF when they can.', 'octopus-image-optimizer'),
						'<code>proc_open()</code>',
						'<code>exec()</code>',
						'<code>disable_functions</code>'
					);
					?>
				</p>
			</div>
		<?php endif; ?>
		<div class="octopus-card octopus-card--hero">
			<div class="octopus-savings js-octopus-savings">
				<div class="octopus-savings__main js-octopus-savings-main"<?php echo $savings['images'] ? '' : ' hidden'; ?>>
					<p class="octopus-savings__value">
						<?php
						/* translators: %s: a size, e.g. 12.5 MB. */
						$this->printf_html(__('%s saved', 'octopus-image-optimizer'), '<span class="js-octopus-savings-value" data-savings="saved">' . esc_html($savings['saved']) . '</span>');
						?>
					</p>
					<?php
					// Before the page paints, shows 0 over the size: it rolls up the first time the overview shows (Savings.js).
					wp_print_inline_script_tag(
						"(() => {
							const saved = document.currentScript.previousElementSibling.querySelector('.js-octopus-savings-value');
							if (!matchMedia('(prefers-reduced-motion: reduce)').matches) {
								saved.dataset.value = saved.textContent.replace(/\d(?:[\d.,\s]*\d)?/, (number) => (/[.,]\d$/.test(number) ? '0' + number.at(-2) + '0' : '0'));
								saved.classList.add('octopus-savings__rolling');
							}
						})();"
					);
					?>
					<p class="octopus-savings__detail">
						<?php
						/* translators: %s: a reduction, e.g. −48 %. */
						$this->printf_html(__('%s on what the site serves', 'octopus-image-optimizer'), '<span class="octopus-savings__reduction js-octopus-savings-value" data-savings="reduction">' . esc_html($savings['reduction']) . '</span>');
						?>
						<span class="octopus-savings__hint js-octopus-savings-hint"<?php echo $savings['hint'] ? '' : ' hidden'; ?>>
							&middot; <a class="js-octopus-tab-link" href="#delivery" data-tab="delivery">
								<?php
								/* translators: %s: a reduction, e.g. −62 %. */
								$this->printf_html(__('%s with WebP and AVIF served', 'octopus-image-optimizer'), '<span class="js-octopus-savings-value" data-savings="modern">' . esc_html($savings['modern']) . '</span>');
								?>
							</a>
						</span>
					</p>
					<p class="octopus-disk">
						<span class="octopus-disk__label">
							<span class="dashicons dashicons-database" aria-hidden="true"></span>
							<?php esc_html_e('On the disk', 'octopus-image-optimizer'); ?>
						</span>
						<span class="octopus-disk__item">
							<?php
							/* translators: %s: the size of the original images, e.g. 4.2 MB. */
							$this->printf_html(__('%s of originals kept', 'octopus-image-optimizer'), '<strong class="js-octopus-savings-value" data-savings="originals">' . esc_html($savings['originals']) . '</strong>');
							?>
						</span>
						<span class="octopus-disk__item">
							<?php
							/* translators: %s: the size of the WebP and AVIF files, e.g. 916.6 KB. */
							$this->printf_html(__('%s of WebP and AVIF', 'octopus-image-optimizer'), '<strong class="js-octopus-savings-value" data-savings="formats">' . esc_html($savings['formats']) . '</strong>');
							?>
						</span>
					</p>
				</div>
				<div class="octopus-savings__empty js-octopus-savings-empty"<?php echo $savings['images'] ? ' hidden' : ''; ?>>
					<p class="octopus-savings__value"><?php esc_html_e('Ready to squeeze', 'octopus-image-optimizer'); ?></p>
					<p class="octopus-savings__detail"><?php esc_html_e('OIO optimises your images on your own server: free, unlimited, and they never leave it.', 'octopus-image-optimizer'); ?></p>
				</div>
				<?php // Filled while new images wait for their optimisation, not those optimised with older settings. ?>
				<a class="octopus-cta js-octopus-cta js-octopus-tab-link<?php echo $counts[Status::TO_OPTIMISE] ? ' octopus-cta--filled' : ''; ?>" href="#optimise" data-tab="optimise">
					<?php esc_html_e('Optimise the media library', 'octopus-image-optimizer'); ?>
					<span class="octopus-cta__arrow dashicons dashicons-arrow-right-alt" aria-hidden="true"></span>
				</a>
			</div>
			<ul class="octopus-counts">
				<?php foreach (self::count_labels() as $status => $label) : ?>
					<li class="octopus-counts__item octopus-counts__item--<?php echo esc_attr($status); ?>">
						<span class="octopus-counts__value js-octopus-count" data-count="<?php echo esc_attr($status); ?>"><?php echo esc_html(number_format_i18n($counts[$status])); ?></span>
						<span class="octopus-counts__label"><?php echo esc_html($label); ?></span>
					</li>
				<?php endforeach; ?>
			</ul>
		</div>

		<h2 class="octopus-heading"><?php esc_html_e('Formats', 'octopus-image-optimizer'); ?></h2>
		<div class="octopus-libraries js-octopus-live" id="octopus-overview-formats">
			<?php foreach ($formats as $id => $format) : ?>
				<?php $state = self::state($format); ?>
				<a class="octopus-library octopus-library--<?php echo esc_attr($state); ?> js-octopus-tab-link" href="#settings-<?php echo esc_attr($id); ?>" data-tab="settings" data-target="octopus-settings-<?php echo esc_attr($id); ?>">
					<span class="octopus-library__head">
						<span class="octopus-library__format"><?php echo esc_html($format['title']); ?></span>
						<?php $this->render_badge($state, self::state_label($state)); ?>
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
	 */
	private function render_engine($format)
	{
		$runs = Libraries::can_execute();
		if ($format['installed']) {
			$name = $format['library'];
			$state = __('installed', 'octopus-image-optimizer');
		} elseif ($format['engine']) {
			$name = $format['engine'];
			$state = $runs
				/* translators: %s: a library, e.g. cwebp. */
				? sprintf(__('%s not installed', 'octopus-image-optimizer'), $format['library'])
				/* translators: %s: a library, e.g. cwebp. */
				: sprintf(__('%s cannot run', 'octopus-image-optimizer'), $format['library']);
		} else {
			$name = $format['library'];
			$state = $runs ? __('not installed', 'octopus-image-optimizer') : __('cannot run', 'octopus-image-optimizer');
		}
		?>
		<span class="octopus-engine octopus-engine--<?php echo $format['engine'] ? 'on' : 'off'; ?>">
			<span class="octopus-engine__name"><?php echo esc_html($name); ?></span>
			<span class="octopus-engine__state"><?php echo esc_html($state); ?></span>
			<?php if ($format['version']) : ?>
				<span class="octopus-engine__version"><?php echo esc_html($format['version']); ?></span>
			<?php endif; ?>
		</span>
		<?php foreach ($format['extras'] as $extra => $installed) : ?>
			<span class="octopus-engine octopus-engine--<?php echo $installed ? 'on' : 'off'; ?>">
				<span class="octopus-engine__name"><?php echo esc_html($extra); ?></span>
				<span class="octopus-engine__state"><?php echo esc_html($installed ? __('installed', 'octopus-image-optimizer') : ($runs ? __('not installed', 'octopus-image-optimizer') : __('cannot run', 'octopus-image-optimizer'))); ?></span>
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
	 */
	private function render_optimise($counts, $jobs)
	{
		?>
		<div class="octopus-card">
			<header class="octopus-card__header">
				<span class="octopus-card__icon dashicons dashicons-images-alt2" aria-hidden="true"></span>
				<div>
					<h2 class="octopus-card__title"><?php esc_html_e('Optimise the media library', 'octopus-image-optimizer'); ?></h2>
					<p class="octopus-card__description"><?php esc_html_e('These jobs run in the background, page closed or not, with the lowest priority on the server.', 'octopus-image-optimizer'); ?></p>
				</div>
			</header>
			<?php if ($jobs) : ?>
				<?php $done = $counts['total'] > 0 && $counts[Status::OPTIMISED] === $counts['total']; ?>
				<div class="octopus-done js-octopus-all-optimised" role="status"<?php echo $done ? '' : ' hidden'; ?>>
					<span class="octopus-confetti js-octopus-confetti" aria-hidden="true"></span>
					<span class="octopus-done__icon dashicons dashicons-yes-alt" aria-hidden="true"></span>
					<p class="octopus-done__text js-octopus-live" id="octopus-optimise-done">
						<strong><?php esc_html_e('All your images are optimised.', 'octopus-image-optimizer'); ?></strong>
						<?php if (Settings::get('optimise_uploads')) : ?>
							<?php esc_html_e('Nothing left to do: the new ones are optimised on upload.', 'octopus-image-optimizer'); ?>
						<?php else : ?>
							<?php esc_html_e('The new uploads will wait here, to optimise when you like.', 'octopus-image-optimizer'); ?>
						<?php endif; ?>
					</p>
				</div>
				<div class="octopus-actions">
					<div class="octopus-action octopus-action--primary">
						<span class="octopus-action__icon dashicons dashicons-format-image" aria-hidden="true"></span>
						<h3 class="octopus-action__title"><?php esc_html_e('New images', 'octopus-image-optimizer'); ?></h3>
						<p class="octopus-action__count js-octopus-count" data-count="new"><?php echo esc_html(number_format_i18n($counts[Status::TO_OPTIMISE] + $counts[Status::ERROR])); ?></p>
						<p class="octopus-action__text"><?php esc_html_e('Not optimised yet, or their optimisation failed.', 'octopus-image-optimizer'); ?></p>
						<button type="button" class="button button-primary octopus-action__button js-octopus-job-start" data-job="new" disabled><?php esc_html_e('Optimise new images', 'octopus-image-optimizer'); ?></button>
					</div>
					<div class="octopus-action">
						<span class="octopus-action__icon dashicons dashicons-update" aria-hidden="true"></span>
						<h3 class="octopus-action__title"><?php esc_html_e('Outdated images', 'octopus-image-optimizer'); ?></h3>
						<p class="octopus-action__count js-octopus-count" data-count="outdated"><?php echo esc_html(number_format_i18n($counts[Status::OUTDATED])); ?></p>
						<p class="octopus-action__text"><?php esc_html_e('Optimised before a quality, a format, the maximum size or the thumbnail sizes changed. Optimised again from their originals.', 'octopus-image-optimizer'); ?></p>
						<button type="button" class="button octopus-action__button js-octopus-job-start" data-job="outdated" disabled><?php esc_html_e('Re-optimise outdated images', 'octopus-image-optimizer'); ?></button>
					</div>
					<div class="octopus-action">
						<span class="octopus-action__icon dashicons dashicons-images-alt" aria-hidden="true"></span>
						<h3 class="octopus-action__title"><?php esc_html_e('All images', 'octopus-image-optimizer'); ?></h3>
						<p class="octopus-action__count js-octopus-count" data-count="total"><?php echo esc_html(number_format_i18n($counts['total'])); ?></p>
						<p class="octopus-action__text"><?php esc_html_e('Every image optimised again from its original, with its thumbnails made again.', 'octopus-image-optimizer'); ?></p>
						<button type="button" class="button octopus-action__button js-octopus-job-start" data-job="all" data-confirm="<?php esc_attr_e('Re-optimise all images from their originals, with their thumbnails? This can take a while.', 'octopus-image-optimizer'); ?>" disabled><?php esc_html_e('Re-optimise all images', 'octopus-image-optimizer'); ?></button>
					</div>
				</div>
			<?php else : ?>
				<?php $this->render_no_jobs(); ?>
			<?php endif; ?>
			<p class="octopus-card__footer js-octopus-live" id="octopus-optimise-footer">
				<?php if (Settings::get('optimise_uploads')) : ?>
					<span class="octopus-uploads octopus-uploads--on"><?php esc_html_e('New uploads are optimised right away.', 'octopus-image-optimizer'); ?></span>
				<?php else : ?>
					<span class="octopus-uploads octopus-uploads--off">
						<?php esc_html_e('New uploads wait here, as new images.', 'octopus-image-optimizer'); ?>
						<a class="js-octopus-tab-link" href="#settings" data-tab="settings" data-target="octopus-settings-general"><?php esc_html_e('Optimise them on upload', 'octopus-image-optimizer'); ?></a>
					</span>
				<?php endif; ?>
				<a href="<?php echo esc_url(admin_url('upload.php?mode=list')); ?>"><?php esc_html_e('See each image in the Media library', 'octopus-image-optimizer'); ?></a>
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
	 */
	private function render_delivery($display, $formats, $counts)
	{
		$server = Htaccess::server();
		$names = ['apache' => 'Apache', 'nginx' => 'nginx', 'other' => __('Your server', 'octopus-image-optimizer')];
		$made = self::with_formats();
		$total = $counts['total'];
		$percent = $total ? min(100, (int)round($made / $total * 100)) : 0;
		$rewrite = $display['fields']['serve_rewrite'];
		if ($server === 'apache') {
			// OIO writes the rules: the box says so.
			/* translators: %s: uploads/.htaccess */
			$rewrite['note'] = sprintf(__('Apache: ticking it writes the rules below in %s, unticking it removes them.', 'octopus-image-optimizer'), '<code>uploads/.htaccess</code>');
		} else {
			// OIO cannot write the rules: the option tells they are in place.
			/* translators: %s: the server, e.g. nginx. */
			$rewrite['note'] = sprintf(__('%s: add the rules below to its configuration, then tick this box.', 'octopus-image-optimizer'), esc_html($names[$server]));
			/* translators: 1: the server, e.g. nginx, 2: .htaccess. */
			$rewrite['help'] = sprintf(__('%1$s does not read %2$s files: add the rules below to its configuration, then tick this box so that OIO counts the bytes saved. The HTML does not change, and every image is served, CSS backgrounds and custom themes included.', 'octopus-image-optimizer'), esc_html($names[$server]), '<code>.htaccess</code>');
		}
		?>
		<ol class="octopus-steps">
			<li class="octopus-step">
				<header class="octopus-step__header">
					<span class="octopus-step__number" aria-hidden="true">1</span>
					<div class="octopus-step__heading">
						<h2 class="octopus-step__title"><?php esc_html_e('Generate', 'octopus-image-optimizer'); ?></h2>
						<p class="octopus-step__description"><?php esc_html_e('The WebP and AVIF versions are made while the images are optimised.', 'octopus-image-optimizer'); ?></p>
					</div>
				</header>
				<div class="octopus-step__body octopus-generate js-octopus-live" id="octopus-delivery-generate">
					<p class="octopus-generate__value">
						<strong><?php echo esc_html(number_format_i18n($made)); ?></strong>
						<?php
						/* translators: %s: the number of images. */
						echo esc_html(sprintf(_n('of %s image has its WebP or AVIF version', 'of %s images have their WebP or AVIF version', $total, 'octopus-image-optimizer'), number_format_i18n($total)));
						?>
					</p>
					<span class="octopus-meter" role="img" aria-label="<?php echo esc_attr($percent . ' %'); ?>"><span class="octopus-meter__value" style="width: <?php echo esc_attr($percent); ?>%"></span></span>
					<ul class="octopus-generate__formats">
						<?php foreach (['webp', 'avif'] as $id) : ?>
							<?php $state = self::state($formats[$id]); ?>
							<li>
								<a class="octopus-chip js-octopus-tab-link" href="#settings-<?php echo esc_attr($id); ?>" data-tab="settings" data-target="octopus-settings-<?php echo esc_attr($id); ?>">
									<span class="octopus-dot octopus-dot--<?php echo esc_attr($state); ?>" aria-hidden="true"></span>
									<span class="octopus-chip__name"><?php echo esc_html($formats[$id]['title']); ?></span>
									<span class="octopus-chip__state"><?php echo esc_html(self::state_label($state)); ?></span>
								</a>
							</li>
						<?php endforeach; ?>
					</ul>
					<?php if ($made < $total) : ?>
						<a class="button octopus-generate__button js-octopus-tab-link" href="#optimise" data-tab="optimise"><?php esc_html_e('Optimise the media library', 'octopus-image-optimizer'); ?></a>
					<?php endif; ?>
				</div>
			</li>

			<li class="octopus-step octopus-step--wide">
				<header class="octopus-step__header">
					<span class="octopus-step__number" aria-hidden="true">2</span>
					<div class="octopus-step__heading">
						<h2 class="octopus-step__title"><?php echo esc_html($display['title']); ?></h2>
						<p class="octopus-step__description"><?php echo esc_html($display['description']); ?></p>
					</div>
				</header>
				<div class="octopus-step__body octopus-methods">
					<div class="octopus-method">
						<?php $this->render_field('serve_picture', $display['fields']['serve_picture']); ?>
					</div>
					<div class="octopus-method">
						<?php $this->render_field('serve_rewrite', $rewrite); ?>
					</div>
				</div>
				<footer class="octopus-step__actions octopus-save">
					<?php submit_button(__('Save changes', 'octopus-image-optimizer'), 'primary', 'submit', false, ['id' => 'octopus-save-delivery']); ?>
				</footer>
			</li>

			<li class="octopus-step octopus-test">
				<header class="octopus-step__header">
					<span class="octopus-step__number" aria-hidden="true">3</span>
					<div class="octopus-step__heading">
						<h2 class="octopus-step__title"><?php esc_html_e('Test', 'octopus-image-optimizer'); ?></h2>
						<p class="octopus-step__description"><?php esc_html_e('Requests an optimised image as a browser would.', 'octopus-image-optimizer'); ?></p>
					</div>
				</header>
				<div class="octopus-step__body">
					<ul class="octopus-test__list">
						<li><?php esc_html_e('Are the WebP and AVIF files served with their type?', 'octopus-image-optimizer'); ?></li>
						<li><?php esc_html_e('Does the server answer the image with them?', 'octopus-image-optimizer'); ?></li>
						<li><?php esc_html_e('Are the originals kept private?', 'octopus-image-optimizer'); ?></li>
					</ul>
					<button type="button" class="button button-primary octopus-test__button js-octopus-delivery-test"><?php esc_html_e('Run the test', 'octopus-image-optimizer'); ?></button>
					<p class="octopus-test__summary js-octopus-test-summary" aria-live="polite" hidden></p>
				</div>
			</li>
		</ol>

		<div class="octopus-card octopus-test__result js-octopus-test-result" hidden>
			<header class="octopus-card__header">
				<span class="octopus-card__icon dashicons dashicons-yes-alt" aria-hidden="true"></span>
				<div>
					<h2 class="octopus-card__title"><?php esc_html_e('Test results', 'octopus-image-optimizer'); ?></h2>
					<p class="octopus-card__description octopus-test__sample js-octopus-test-sample"></p>
				</div>
			</header>
			<div class="octopus-card__body">
				<ul class="octopus-checks js-octopus-test-checks"></ul>
				<template class="js-octopus-check-template">
					<li class="octopus-checks__item js-octopus-check">
						<span class="octopus-checks__icon dashicons" aria-hidden="true"></span>
						<span class="octopus-checks__label js-octopus-check-label"></span>
						<span class="octopus-checks__detail js-octopus-check-detail"></span>
						<span class="octopus-checks__advice js-octopus-check-advice"></span>
					</li>
				</template>
			</div>
		</div>

		<h2 class="octopus-heading"><?php esc_html_e('Rules and code', 'octopus-image-optimizer'); ?></h2>
		<?php if ($server === 'apache') : ?>
			<?php $written = Htaccess::rules_written(); ?>
			<details class="octopus-fold">
				<summary class="octopus-fold__summary">
					<span class="octopus-fold__title"><?php esc_html_e('Rewrite rules for Apache and LiteSpeed', 'octopus-image-optimizer'); ?></span>
					<?php $this->render_badge($written ? 'on' : 'muted', $written ? __('Written in uploads/.htaccess', 'octopus-image-optimizer') : __('Not written', 'octopus-image-optimizer'), 'octopus-delivery-rules-state'); ?>
				</summary>
				<div class="octopus-fold__content">
					<p class="octopus-fold__text">
							<?php
							/* translators: %s: uploads/.htaccess */
							$this->printf_html(__('OIO writes them in %s when the server option is on, and removes them when it is off.', 'octopus-image-optimizer'), '<code>uploads/.htaccess</code>');
							?>
						</p>
					<?php $this->render_code(implode("\n", Htaccess::apache_rules()), 'octopus-code-apache'); ?>
				</div>
			</details>
		<?php else : ?>
			<?php if ($server === 'other') : ?>
				<details class="octopus-fold">
					<summary class="octopus-fold__summary"><span class="octopus-fold__title"><?php esc_html_e('Rewrite rules for Apache and LiteSpeed', 'octopus-image-optimizer'); ?></span></summary>
					<div class="octopus-fold__content">
						<p class="octopus-fold__text">
								<?php
								/* translators: %s: uploads/.htaccess */
								$this->printf_html(__('In %s, written by OIO when the server option is on.', 'octopus-image-optimizer'), '<code>uploads/.htaccess</code>');
								?>
							</p>
						<?php $this->render_code(implode("\n", Htaccess::apache_rules()), 'octopus-code-apache'); ?>
					</div>
				</details>
			<?php endif; ?>
			<details class="octopus-fold">
				<summary class="octopus-fold__summary"><span class="octopus-fold__title"><?php esc_html_e('Rewrite rules for nginx', 'octopus-image-optimizer'); ?></span></summary>
				<div class="octopus-fold__content">
					<p class="octopus-fold__text"><?php esc_html_e('Add them to the configuration of the site, reload nginx, then tick the server option.', 'octopus-image-optimizer'); ?></p>
					<?php $this->render_code(Htaccess::nginx_rules(), 'octopus-code-nginx'); ?>
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
	 */
	private static function with_formats()
	{
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- One count over the media library, once per page.
		return (int)$wpdb->get_var($wpdb->prepare(
			"SELECT COUNT(DISTINCT m.post_id) FROM {$wpdb->postmeta} m
			INNER JOIN {$wpdb->posts} p ON p.ID = m.post_id
			WHERE m.meta_key IN (%s, %s)
			AND p.post_type = 'attachment' AND p.post_status = 'inherit'
			AND p.post_mime_type IN (" . implode(', ', array_fill(0, count(Files::MIMES), '%s')) . ")",
			array_merge([WebP::meta_key(), AVIF::meta_key()], Files::MIMES)
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
	 */
	private function render_settings($general, $formats)
	{
		?>
		<div class="octopus-card" id="octopus-settings-general">
			<header class="octopus-card__header">
				<span class="octopus-card__icon dashicons dashicons-upload" aria-hidden="true"></span>
				<div>
					<h2 class="octopus-card__title"><?php echo esc_html($general['title']); ?></h2>
					<p class="octopus-card__description"><?php echo esc_html($general['description']); ?></p>
				</div>
			</header>
			<div class="octopus-card__body octopus-fields">
				<?php foreach ($general['fields'] as $name => $field) : ?>
					<?php $this->render_field($name, $field); ?>
				<?php endforeach; ?>
			</div>
		</div>

		<div class="octopus-formats">
			<?php foreach ($formats as $id => $format) : ?>
				<?php
				$state = self::state($format);
				$switch = $id . '_enable';
				$fields = $format['fields'];
				unset($fields[$switch]);
				?>
				<?php // Its state follows the settings as they are saved (Form.js): its class only, its fields kept. ?>
				<section class="octopus-format octopus-format--<?php echo esc_attr($state); ?> js-octopus-live" id="octopus-settings-<?php echo esc_attr($id); ?>" data-live="class">
					<header class="octopus-format__header">
						<div class="octopus-format__top">
							<h2 class="octopus-format__title"><?php echo esc_html($format['title']); ?></h2>
							<?php $this->render_badge($state, self::state_label($state), 'octopus-settings-' . $id . '-state'); ?>
							<?php $this->render_switch($switch, $format['fields'][$switch]); ?>
						</div>
						<div class="octopus-format__engines">
							<?php $this->render_engine($format); ?>
						</div>
					</header>
					<p class="octopus-format__description"><?php echo wp_kses($format['fields'][$switch]['help'], ['strong' => [], 'code' => []]); ?></p>
					<?php if (!Libraries::can_execute()) : ?>
						<?php // Installing would not help: the overview says why. ?>
					<?php elseif (!$format['installed'] && $format['engine']) : ?>
						<details class="octopus-install octopus-install--advice">
							<summary class="octopus-install__summary">
								<?php
								/* translators: %s: a library, e.g. cwebp. */
								echo esc_html(sprintf(__('Recommended: install %s', 'octopus-image-optimizer'), $format['library']));
								?>
							</summary>
							<p class="octopus-install__text"><?php echo esc_html(sprintf($format['recommendation'], $format['engine'])); ?></p>
							<?php $this->render_template('health' . DIRECTORY_SEPARATOR . $format['template']); ?>
						</details>
					<?php elseif (!$format['installed']) : ?>
						<details class="octopus-install">
							<summary class="octopus-install__summary">
								<?php
								/* translators: %s: a library, e.g. cwebp. */
								echo esc_html(sprintf(__('How to install %s', 'octopus-image-optimizer'), $format['library']));
								?>
							</summary>
							<?php $this->render_template('health' . DIRECTORY_SEPARATOR . $format['template']); ?>
						</details>
					<?php elseif ($format['upgrade']) : ?>
						<details class="octopus-install octopus-install--advice">
							<summary class="octopus-install__summary">
								<?php
								/* translators: %s: a library, e.g. cwebp. */
								echo esc_html(sprintf(__('Recommended: install %s', 'octopus-image-optimizer'), $format['preferred']));
								?>
							</summary>
							<p class="octopus-install__text"><?php echo esc_html($format['preference']); ?></p>
							<?php $this->render_template('health' . DIRECTORY_SEPARATOR . $format['preferred'] . '.php'); ?>
						</details>
					<?php endif; ?>
					<details class="octopus-format__settings js-octopus-fold">
						<summary class="octopus-format__summary"><?php esc_html_e('Settings', 'octopus-image-optimizer'); ?></summary>
						<div class="octopus-format__fields octopus-fields">
							<?php foreach ($fields as $name => $field) : ?>
								<?php $this->render_field($name, self::availability($field, $format)); ?>
							<?php endforeach; ?>
							<?php foreach ($format['optional'] as $name => $use) : ?>
								<?php if ($format['installed'] && !$format['extras'][$name]) : ?>
									<details class="octopus-install octopus-install--advice">
										<summary class="octopus-install__summary">
											<?php
											/* translators: 1: a library, e.g. pngquant, 2: what it is for, e.g. lossy compression. */
											echo esc_html(sprintf(__('How to install %1$s, for the %2$s', 'octopus-image-optimizer'), $name, $use));
											?>
										</summary>
										<?php $this->render_template('health' . DIRECTORY_SEPARATOR . $format['template']); ?>
									</details>
								<?php endif; ?>
							<?php endforeach; ?>
						</div>
					</details>
				</section>
			<?php endforeach; ?>
		</div>

		<div class="octopus-page__save octopus-save">
			<?php submit_button(__('Save changes', 'octopus-image-optimizer'), 'primary', 'submit', false, ['id' => 'octopus-save-settings']); ?>
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
	 */
	private function render_maintenance($jobs)
	{
		?>
		<div class="octopus-card">
			<header class="octopus-card__header">
				<span class="octopus-card__icon dashicons dashicons-trash" aria-hidden="true"></span>
				<div>
					<h2 class="octopus-card__title"><?php esc_html_e('Remove WebP and AVIF', 'octopus-image-optimizer'); ?></h2>
					<p class="octopus-card__description">
						<?php
						/* translators: %s: the number of images. */
						$this->printf_html(__('Deletes the WebP and AVIF files of the %s images processed. The JPG and PNG files stay compressed, and the originals kept.', 'octopus-image-optimizer'), '<strong class="js-octopus-count" data-count="processed">' . esc_html(number_format_i18n(count(Cleaner::ids()))) . '</strong>');
						?>
					</p>
				</div>
			</header>
			<?php if ($jobs) : ?>
				<div class="octopus-confirm">
					<label class="octopus-confirm__label"><input type="checkbox" class="octopus-confirm__input js-octopus-job-confirm" data-confirm-for="remove_formats"> <?php esc_html_e('I understand the WebP and AVIF files will be deleted, until the images are optimised again.', 'octopus-image-optimizer'); ?></label>
					<button type="button" class="button octopus-confirm__button js-octopus-job-start" data-job="remove_formats" disabled><?php esc_html_e('Remove WebP and AVIF', 'octopus-image-optimizer'); ?></button>
				</div>
			<?php else : ?>
				<?php $this->render_no_jobs(); ?>
			<?php endif; ?>
		</div>

		<div class="octopus-card octopus-card--danger">
			<header class="octopus-card__header">
				<span class="octopus-card__icon dashicons dashicons-backup" aria-hidden="true"></span>
				<div>
					<h2 class="octopus-card__title"><?php esc_html_e('Restore original images', 'octopus-image-optimizer'); ?></h2>
					<p class="octopus-card__description"><?php esc_html_e('Puts the original images back and deletes every file made by OIO (WebP, AVIF, backups). Do it before deleting OIO: deleting it keeps the files as they are.', 'octopus-image-optimizer'); ?></p>
				</div>
			</header>
			<?php if ($jobs) : ?>
				<div class="octopus-confirm">
					<label class="octopus-confirm__label"><input type="checkbox" class="octopus-confirm__input js-octopus-job-confirm" data-confirm-for="restore"> <?php esc_html_e('I understand the images will no longer be optimised, and their WebP and AVIF files will be deleted.', 'octopus-image-optimizer'); ?></label>
					<button type="button" class="button octopus-confirm__button octopus-button--danger js-octopus-job-start" data-job="restore" disabled><?php esc_html_e('Restore original images', 'octopus-image-optimizer'); ?></button>
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
	 */
	private function render_no_jobs()
	{
		?>
		<p class="octopus-card__notice">
			<?php
			/* translators: 1: composer install, 2: wp octopus run */
			$this->printf_html(__('The background jobs need the dependencies of OIO: run %1$s in its folder. WP-CLI (%2$s) works without them.', 'octopus-image-optimizer'), '<code>composer install</code>', '<code>wp octopus run</code>');
			?>
		</p>
		<?php
	}

	/**
	 * Renders the progress of the background job, shown
	 * on every tab while it runs, and its result.
	 *
	 * @since 1.0.0
	 */
	private function render_job()
	{
		?>
		<div class="octopus-job js-octopus-job" hidden>
			<div class="octopus-job__progress">
				<div class="octopus-job__head">
					<span class="octopus-job__icon dashicons dashicons-update" aria-hidden="true"></span>
					<p class="octopus-job__label js-octopus-job-label" aria-live="polite"></p>
					<span class="octopus-job__percent js-octopus-job-percent"></span>
					<div class="octopus-job__controls">
						<button type="button" class="button js-octopus-job-control" data-control="pause"><?php esc_html_e('Pause', 'octopus-image-optimizer'); ?></button>
						<button type="button" class="button js-octopus-job-control" data-control="resume"><?php esc_html_e('Resume', 'octopus-image-optimizer'); ?></button>
						<button type="button" class="button-link button-link-delete js-octopus-job-control" data-control="cancel"><?php esc_html_e('Cancel', 'octopus-image-optimizer'); ?></button>
					</div>
				</div>
				<progress class="octopus-job__bar js-octopus-job-bar" max="100" value="0"></progress>
			</div>
			<div class="octopus-result js-octopus-job-result" role="status" aria-live="polite">
				<span class="octopus-confetti js-octopus-confetti" aria-hidden="true"></span>
				<span class="octopus-result__icon dashicons" aria-hidden="true"></span>
				<div class="octopus-result__body">
					<p class="octopus-result__title js-octopus-job-title"></p>
					<p class="octopus-result__detail">
						<span class="js-octopus-job-detail"></span>
						<span class="js-octopus-job-errors" hidden>
							<span class="js-octopus-job-errors-count"></span>
							<a href="<?php echo esc_url(admin_url('upload.php?mode=list')); ?>"><?php esc_html_e('see the errors in the Media library', 'octopus-image-optimizer'); ?></a>.
						</span>
						<span class="js-octopus-job-missing" hidden></span>
					</p>
				</div>
				<button type="button" class="octopus-result__close js-octopus-job-control" data-control="dismiss">
					<span class="dashicons dashicons-no-alt" aria-hidden="true"></span>
					<span class="screen-reader-text"><?php esc_html_e('Dismiss', 'octopus-image-optimizer'); ?></span>
				</button>
			</div>
		</div>
		<?php
	}

	/**
	 * Renders how to use octopus_image() in a custom
	 * theme, whose images the display option does not
	 * change.
	 *
	 * @since 1.0.0
	 */
	private function render_theme_help()
	{
		$code = "<?php\nif (function_exists('octopus_image')) {\n\techo octopus_image(\$image_id, 'my-class');\n} else {\n\techo wp_get_attachment_image(\$image_id, 'full');\n}";
		?>
		<details class="octopus-fold">
			<summary class="octopus-fold__summary">
				<span class="octopus-fold__title">
					<?php
					/* translators: %s: octopus_image() */
					$this->printf_html(__('Custom themes: the %s helper', 'octopus-image-optimizer'), '<code>octopus_image()</code>');
					?>
				</span>
			</summary>
			<div class="octopus-fold__content">
				<p class="octopus-fold__text">
					<?php
					/* translators: 1: <picture>, 2: octopus_image() */
					$this->printf_html(__('Images output with your own markup (e.g. from an ACF field) are not wrapped in a %1$s by the HTML option: serve them from the server, or use the %2$s helper, which returns their %1$s whatever the options.', 'octopus-image-optimizer'), '<code>&lt;picture&gt;</code>', '<code>octopus_image()</code>');
					?>
				</p>
				<?php $this->render_code($code, 'octopus-code-theme'); ?>
				<p class="octopus-fold__text">
					<?php
					$this->printf_html(
						/* translators: 1: <picture>, 2: true, 3: data-src, 4: data-srcset, 5: function_exists() */
						__('The second argument is the class of the %1$s element. Pass %2$s as third argument to output %3$s and %4$s for a JavaScript lazy loader. Keep the %5$s check: without it, the pages break while OIO is deactivated.', 'octopus-image-optimizer'),
						'<code>&lt;picture&gt;</code>',
						'<code>true</code>',
						'<code>data-src</code>',
						'<code>data-srcset</code>',
						'<code>function_exists()</code>'
					);
					?>
				</p>
			</div>
		</details>
		<?php
	}

	/**
	 * Returns the bytes saved on the media library, as
	 * shown on the overview: the reduction of what the
	 * site serves, the one it would get with the WebP
	 * and AVIF served, and the disk used by OIO. Sent
	 * again with the progress of a job (JobsAjax::status()).
	 *
	 * @return array
	 * @since 1.0.0
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
	 */
	private static function state($format)
	{
		if (!$format['enabled']) {
			return 'muted';
		}
		return $format['engine'] ? 'on' : 'off';
	}

	/**
	 * Returns the labels of the counts of the overview,
	 * by status: a number of images, where the media
	 * column labels one image (MediaColumn::labels()).
	 *
	 * @return array
	 * @since 1.0.0
	 */
	private static function count_labels()
	{
		return [
			Status::OPTIMISED => _x('Optimised', 'images', 'octopus-image-optimizer'),
			Status::TO_OPTIMISE => _x('To optimise', 'images', 'octopus-image-optimizer'),
			Status::OUTDATED => _x('Older settings', 'images', 'octopus-image-optimizer'),
			Status::ERROR => _x('Error', 'images', 'octopus-image-optimizer'),
		];
	}

	/**
	 * Returns the label of a format state, see state().
	 *
	 * @param string $state
	 * @return string
	 * @since 1.0.0
	 */
	private static function state_label($state)
	{
		$labels = [
			'on' => __('Active', 'octopus-image-optimizer'),
			'off' => __('Library unavailable', 'octopus-image-optimizer'),
			'muted' => __('Disabled', 'octopus-image-optimizer'),
		];
		return $labels[$state];
	}

	/**
	 * Prints a translated text, escaped, with HTML in
	 * its placeholders, e.g. a <code> or a counter.
	 *
	 * @param string $format
	 * @param string ...$html Escaped by the caller.
	 * @since 1.0.0
	 */
	private function printf_html($format, ...$html)
	{
		echo sprintf(esc_html($format), ...$html); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The text is escaped here, its placeholders by the caller.
	}

	/**
	 * Renders a block of code, with its copy button
	 * (CopyButton.js, hidden without JS).
	 *
	 * @param string $code
	 * @param string $id Unique on the page.
	 * @since 1.0.0
	 */
	private function render_code($code, $id)
	{
		?>
		<div class="octopus-code-block">
			<pre class="octopus-code" id="<?php echo esc_attr($id); ?>"><code><?php echo esc_html($code); ?></code></pre>
			<button type="button" class="octopus-copy js-octopus-copy" aria-controls="<?php echo esc_attr($id); ?>"><?php esc_html_e('Copy', 'octopus-image-optimizer'); ?></button>
		</div>
		<?php
	}

	/**
	 * Renders a command or an address on one line, with
	 * its copy button after it.
	 *
	 * @param string $command
	 * @param string $class Of the code.
	 * @since 1.0.0
	 */
	private function render_command($command, $class = '')
	{
		$id = 'octopus-command-' . sanitize_title($command);
		printf(
			'<code class="%1$s" id="%2$s">%3$s</code> <button type="button" class="octopus-copy js-octopus-copy" aria-controls="%2$s">%4$s</button>',
			esc_attr($class),
			esc_attr($id),
			esc_html($command),
			esc_html__('Copy', 'octopus-image-optimizer')
		);
	}

	/**
	 * Renders a status badge.
	 *
	 * @param string $variant on, off or muted.
	 * @param string $label
	 * @param string $id To follow the settings and the jobs.
	 * @since 1.0.0
	 */
	private function render_badge($variant, $label, $id = '')
	{
		if ($id) {
			// It follows the settings and the jobs (Form.js).
			printf('<span class="octopus-badge octopus-badge--%s js-octopus-live" id="%s">%s</span>', esc_attr($variant), esc_attr($id), esc_html($label));
			return;
		}
		printf('<span class="octopus-badge octopus-badge--%s">%s</span>', esc_attr($variant), esc_html($label));
	}

	/**
	 * Determines if a value of a setting is one the page
	 * greys, e.g. the lossy PNG compression without
	 * pngquant: the server refuses it as well
	 * (SettingsAjax). Not the switch of a format, never
	 * greyed.
	 *
	 * @param string $name
	 * @param string $value
	 * @return bool
	 * @since 1.0.0
	 */
	public static function unavailable($name, $value)
	{
		foreach (self::formats(self::sections()) as $id => $format) {
			if ($name === $id . '_enable' || !isset($format['fields'][$name])) {
				continue;
			}
			$field = self::availability($format['fields'][$name], $format);
			return $field['disabled'] || isset($field['disabled_options'][(string)$value]);
		}
		return false;
	}

	/**
	 * Greys a setting of a format that nothing can use:
	 * all of them without a library or an image editor
	 * making the format, those of a missing optional
	 * library (e.g. pngquant) or of the library of the
	 * format, missing while the image editor makes it
	 * (the speed of avifenc), and the values needing
	 * one. A field disabled is not sent: it keeps its
	 * value.
	 *
	 * @param array $field
	 * @param array $format See formats().
	 * @return array The field, with disabled and disabled_options.
	 * @since 1.0.0
	 */
	private static function availability($field, $format)
	{
		$missing = function ($library) use ($format) {
			return $library === $format['library'] ? !$format['installed'] : empty($format['extras'][$library]);
		};
		$field['disabled'] = !$format['engine'] || (!empty($field['needs']) && $missing($field['needs']));
		$field['disabled_options'] = [];
		foreach (isset($field['option_needs']) ? $field['option_needs'] : [] as $option => $library) {
			if ($missing($library)) {
				$field['disabled_options'][$option] = $library;
			}
		}
		return $field;
	}

	/**
	 * Renders an option field: a toggle for a checkbox,
	 * a label and its control otherwise.
	 *
	 * @param string $name
	 * @param array $field
	 * @since 1.0.0
	 */
	private function render_field($name, $field)
	{
		$id = 'octopus-' . str_replace('_', '-', $name);
		$input = Settings::OPTION . '[' . $name . ']';
		$value = Settings::get($name);
		$setting = Settings::FIELDS[$name];
		$tags = ['strong' => [], 'code' => []];
		$disabled = !empty($field['disabled']) ? ' disabled' : '';
		// A field shown while another one has a value, e.g. the lossy quality (DependentField.js).
		// Off as the page opens: hidden while the scripts load (fields.css), shown without JS.
		$when = '';
		$off = false;
		if (!empty($field['when'])) {
			$when = sprintf(' data-when="%s" data-when-value="%s"', esc_attr(Settings::OPTION . '[' . $field['when'][0] . ']'), esc_attr($field['when'][1]));
			$off = (string)Settings::get($field['when'][0]) !== (string)$field['when'][1];
		}

		// The label names the control without being a <label>: hovering it would hover the control.
		printf(
			'<div class="octopus-field%s%s%s%s"%s><span class="octopus-field__label" id="%s-label">%s</span>',
			$field['type'] === 'checkbox' ? ' octopus-field--toggle' : '',
			$disabled ? ' octopus-field--disabled' : '',
			$off ? ' octopus-field--off' : '',
			$when ? ' js-octopus-dependent' : '',
			$when, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above.
			esc_attr($id),
			esc_html($field['label'])
		);

		if ($field['type'] === 'checkbox') {
			// An unticked box is not sent: the hidden field sends 0 instead.
			printf(
				'<input type="hidden" name="%s" value="0"><label class="octopus-toggle"><input type="checkbox" class="octopus-toggle__input" id="%s" name="%s" value="1" aria-labelledby="%s-label" %s><span class="octopus-toggle__track" aria-hidden="true"></span></label>',
				esc_attr($input), esc_attr($id), esc_attr($input), esc_attr($id), checked($value, true, false)
			);
		} else {
			echo '<div class="octopus-field__control">';
			if ($field['type'] === 'number') {
				printf(
					'<input type="number" class="octopus-field__input" id="%s" name="%s" value="%s" min="%d" max="%d" step="1" aria-labelledby="%s-label"%s>',
					esc_attr($id), esc_attr($input), esc_attr($value), (int)$setting['min'], (int)$setting['max'], esc_attr($id), esc_attr($disabled)
				);
			} elseif ($field['type'] === 'select') {
				printf('<select class="octopus-field__input" id="%s" name="%s" aria-labelledby="%s-label"%s>', esc_attr($id), esc_attr($input), esc_attr($id), esc_attr($disabled));
				foreach ($field['options'] as $option => $label) {
					printf('<option value="%s" %s>%s</option>', esc_attr($option), selected((string)$value, (string)$option, false), esc_html($label));
				}
				echo '</select>';
			} elseif ($field['type'] === 'choice') {
				// Every value in sight, as buttons side by side: radio buttons underneath. A value
				// needing a missing library is greyed, the first other one chosen, as the encoder does.
				$off = $field['disabled_options'] ?? [];
				if (isset($off[$value])) {
					$value = key(array_diff_key($field['options'], $off));
				}
				printf('<div class="octopus-choice" role="radiogroup" aria-labelledby="%s-label">', esc_attr($id));
				foreach ($field['options'] as $option => $label) {
					printf(
						'<label class="octopus-choice__option%s"%s><input type="radio" class="octopus-choice__input" name="%s" value="%s" %s%s><span class="octopus-choice__label">%s</span></label>',
						isset($off[$option]) ? ' octopus-choice__option--disabled' : '',
						/* translators: %s: a library, e.g. pngquant. */
						isset($off[$option]) ? sprintf(' title="%s"', esc_attr(sprintf(__('%s not installed', 'octopus-image-optimizer'), $off[$option]))) : '', // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped here.
						esc_attr($input), esc_attr($option), checked($value, $option, false), esc_attr($disabled || isset($off[$option]) ? ' disabled' : ''), esc_html($label)
					);
				}
				echo '</div>';
			}
			if (!empty($field['unit'])) {
				printf('<span class="octopus-field__unit">%s</span>', esc_html($field['unit']));
			}
			echo '</div>';
		}

		if (empty($field['note'])) {
			printf('<p class="octopus-field__help">%s</p></div>', wp_kses($field['help'], $tags));
			return;
		}
		// A short note, the full help behind a "?" button (HelpToggle.js hides it, shown without JS).
		printf(
			'<p class="octopus-field__help">%s <button type="button" class="octopus-help__toggle js-octopus-help-toggle" aria-expanded="false" aria-controls="%s-help"><span aria-hidden="true">?</span><span class="screen-reader-text">%s</span></button></p><p class="octopus-field__help octopus-help__text" id="%s-help">%s</p></div>',
			wp_kses($field['note'], $tags), esc_attr($id), /* translators: %s: the label of a setting. */ esc_html(sprintf(__('More about: %s', 'octopus-image-optimizer'), $field['label'])), esc_attr($id), wp_kses($field['help'], $tags)
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
	 */
	private function render_switch($name, $field)
	{
		$input = Settings::OPTION . '[' . $name . ']';
		printf(
			'<input type="hidden" name="%s" value="0"><label class="octopus-toggle octopus-toggle--switch"><input type="checkbox" class="octopus-toggle__input" id="%s" name="%s" value="1" %s><span class="octopus-toggle__track" aria-hidden="true"></span><span class="screen-reader-text">%s</span></label>',
			esc_attr($input), esc_attr('octopus-' . str_replace('_', '-', $name)), esc_attr($input), checked(Settings::get($name), true, false), esc_html($field['label'])
		);
	}

	/**
	 * Returns the option fields, grouped by section.
	 *
	 * @return array
	 * @since 1.0.0
	 */
	private static function sections()
	{
		return [
			'jpg' => [
				'title' => 'JPG',
				'service' => JPG::class,
				'template' => 'jpg.php',
				'fields' => [
					'jpg_enable' => [
						'type' => 'checkbox',
						'label' => __('Compress JPG images', 'octopus-image-optimizer'),
						'help' => __('Compresses the JPG images in place with jpegoptim, and makes them progressive. Their originals are kept.', 'octopus-image-optimizer'),
					],
					'jpg_quality' => [
						'type' => 'number',
						'label' => __('Quality', 'octopus-image-optimizer'),
						'help' => __('From 0 to 100, 80 by default.', 'octopus-image-optimizer'),
					],
				],
			],
			'png' => [
				'title' => 'PNG',
				'service' => PNG::class,
				'template' => 'png.php',
				'optional' => ['pngquant' => __('lossy compression', 'octopus-image-optimizer')],
				'preferred' => 'oxipng',
				'preference' => __('optipng compresses the PNG images for now. Once installed, oxipng replaces it on its own: the files are lighter at the same level, and it is a little faster.', 'octopus-image-optimizer'),
				'fields' => [
					'png_enable' => [
						'type' => 'checkbox',
						'label' => __('Compress PNG images', 'octopus-image-optimizer'),
						'help' => __('Compresses the PNG images in place with oxipng or optipng, after pngquant when lossy. Their originals are kept.', 'octopus-image-optimizer'),
					],
					'png_compression' => [
						'type' => 'choice',
						'label' => __('Compression', 'octopus-image-optimizer'),
						'options' => [
							'lossless' => __('Lossless', 'octopus-image-optimizer'),
							'lossy' => __('Lossy (pngquant)', 'octopus-image-optimizer'),
						],
						'option_needs' => ['lossy' => 'pngquant'],
						'help' => __('Lossy: often 60 to 80 % lighter, for graphics and screenshots. Needs pngquant.', 'octopus-image-optimizer'),
					],
					'png_level' => [
						'type' => 'select',
						'label' => __('Optimisation level', 'octopus-image-optimizer'),
						'options' => ['0' => 'o0', '1' => 'o1', '2' => 'o2', '3' => 'o3', '4' => 'o4', '5' => 'o5', '6' => 'o6', '7' => 'o7'],
						'help' => __('From o0 (fastest) to o7 (smallest files), o2 by default.', 'octopus-image-optimizer'),
					],
					'png_lossy_quality' => [
						'type' => 'number',
						'label' => __('Lossy quality', 'octopus-image-optimizer'),
						'when' => ['png_compression', 'lossy'],
						'needs' => 'pngquant',
						'help' => __('The quality pngquant aims at, 80 by default.', 'octopus-image-optimizer'),
					],
				],
			],
			'webp' => [
				'title' => 'WebP',
				'service' => WebP::class,
				'template' => 'webp.php',
				/* translators: %s: GD or Imagick. */
				'recommendation' => __('%s makes the WebP files for now, in the PHP process. cwebp runs apart from PHP, and makes the PNG graphics and screenshots much lighter.', 'octopus-image-optimizer'),
				'fields' => [
					'webp_enable' => [
						'type' => 'checkbox',
						'label' => __('Make WebP versions', 'octopus-image-optimizer'),
						'help' => __('Makes a WebP version of each image and thumbnail (<code>photo.jpg.webp</code>), lighter than the JPG or PNG.', 'octopus-image-optimizer'),
					],
					'webp_quality' => [
						'type' => 'number',
						'label' => __('Quality', 'octopus-image-optimizer'),
						'help' => __('From 0 to 100, 80 by default.', 'octopus-image-optimizer'),
					],
				],
			],
			'avif' => [
				'title' => 'AVIF',
				'service' => AVIF::class,
				'template' => 'avif.php',
				/* translators: %s: GD or Imagick. */
				'recommendation' => __('%s makes the AVIF files for now, in the PHP process. avifenc runs apart from PHP, on several threads, and keeps the colours of the PNG images sharper.', 'octopus-image-optimizer'),
				'fields' => [
					'avif_enable' => [
						'type' => 'checkbox',
						'label' => __('Make AVIF versions', 'octopus-image-optimizer'),
						'help' => __('Makes an AVIF version of each image and thumbnail (<code>photo.jpg.avif</code>), often the lightest. Kept only when lighter than the WebP and the JPG or PNG.', 'octopus-image-optimizer'),
					],
					'avif_quality' => [
						'type' => 'number',
						'label' => __('Quality', 'octopus-image-optimizer'),
						'help' => __('From 0 to 100 (lossless), 70 by default.', 'octopus-image-optimizer'),
					],
					'avif_speed' => [
						'type' => 'number',
						'label' => __('Speed', 'octopus-image-optimizer'),
						'needs' => 'avifenc',
						'help' => __('From 0 to 10 (fastest, heavier files), 6 by default.', 'octopus-image-optimizer'),
					],
				],
			],
			'general' => [
				'title' => __('Uploads', 'octopus-image-optimizer'),
				'description' => __('What happens to the images added to the media library.', 'octopus-image-optimizer'),
				'fields' => [
					'optimise_uploads' => [
						'type' => 'checkbox',
						'label' => __('Optimise new uploads', 'octopus-image-optimizer'),
						'help' => __('Each image added to the media library is optimised right away. Unticked, they wait in the Optimise tab, as new images.', 'octopus-image-optimizer'),
					],
					'max_size' => [
						'type' => 'number',
						'label' => __('Maximum image size', 'octopus-image-optimizer'),
						'unit' => 'px',
						'help' => __('WordPress scales the bigger images down on upload, and keeps them as their original. Longest side, 2560 by default. <strong>0 means no limit: huge images can exhaust the server.</strong>', 'octopus-image-optimizer'),
					],
				],
			],
			'display' => [
				'title' => __('Serve', 'octopus-image-optimizer'),
				'description' => __('Each browser gets the lightest version it reads: AVIF, then WebP, or the JPG or PNG for older ones. Choose one way, or both.', 'octopus-image-optimizer'),
				'fields' => [
					'serve_picture' => [
						'type' => 'checkbox',
						'label' => __('In the HTML, with <picture>', 'octopus-image-optimizer'),
						'note' => __('Wraps the images of the content in a <code>&lt;picture&gt;</code>. Untick it if the layout breaks.', 'octopus-image-optimizer'),
						'help' => __('Images of the post content, featured images and galleries are wrapped in a <code>&lt;picture&gt;</code> tag offering the AVIF and WebP versions. Works on every server. <strong>Untick this if your theme layout breaks around images.</strong>', 'octopus-image-optimizer'),
					],
					'serve_rewrite' => [
						'type' => 'checkbox',
						'label' => __('From the server, with rewrite rules', 'octopus-image-optimizer'),
						'note' => __('Every image, CSS backgrounds included, the HTML unchanged.', 'octopus-image-optimizer'),
						'help' => __('The server answers <code>photo.jpg</code> with <code>photo.jpg.avif</code> or <code>.webp</code> when the browser reads them. The HTML does not change, and every image is served, CSS backgrounds and custom themes included. A CDN must vary its cache on the <code>Accept</code> header.', 'octopus-image-optimizer'),
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
	 * @since 1.0.0
	 */
	private function render_template($name)
	{
		include OCTOPUS_TEMPLATE_PATH . DIRECTORY_SEPARATOR . $name;
	}
}
