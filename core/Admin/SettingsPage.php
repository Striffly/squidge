<?php

/**
 * SettingsPage
 *
 * The page of OIO, in the Settings menu: its
 * header, its tabs, each rendered by its class in
 * Admin\Tabs, the box of the jobs and the support
 * of the author. The fields of the settings it
 * saves are in Admin\Fields.
 *
 * @package     OIO
 * @author      Clément Gayot
 * @author      Ainsley Clark (Squidge, original author)
 * @category    Class
 * @repo        https://github.com/Striffly/octopus-image-optimizer
 *
 */

namespace Octopus\Admin;

use Octopus\Admin\Tabs\DeliveryTab;
use Octopus\Admin\Tabs\MaintenanceTab;
use Octopus\Admin\Tabs\OptimiseTab;
use Octopus\Admin\Tabs\OverviewTab;
use Octopus\Admin\Tabs\SettingsTab;
use Octopus\Delivery\Htaccess;
use Octopus\Media\Status;
use Octopus\Plugin;
use Octopus\Settings;

if (!defined('ABSPATH')) {
	exit; // Exit if accessed directly
}

class SettingsPage extends View
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
		Ajax::localize($script, 'octopusSettings', Ajax::NONCE);
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
		$sections = Fields::sections();
		$formats = Fields::formats($sections);
		$counts = Status::counts();
		$jobs = Plugin::has_background();
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
					<?php (new OverviewTab())->render($formats, $counts); ?>
				</section>

				<section class="octopus-panel js-octopus-panel" id="octopus-panel-optimise" role="tabpanel" data-panel="optimise">
					<?php (new OptimiseTab())->render($counts, $jobs); ?>
				</section>

				<section class="octopus-panel js-octopus-panel" id="octopus-panel-delivery" role="tabpanel" data-panel="delivery">
					<?php (new DeliveryTab())->render($sections['display'], $formats, $counts); ?>
				</section>

				<section class="octopus-panel js-octopus-panel" id="octopus-panel-settings" role="tabpanel" data-panel="settings">
					<?php (new SettingsTab())->render($sections['general'], $formats); ?>
				</section>

				<section class="octopus-panel js-octopus-panel" id="octopus-panel-maintenance" role="tabpanel" data-panel="maintenance">
					<?php (new MaintenanceTab())->render($jobs); ?>
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

}
