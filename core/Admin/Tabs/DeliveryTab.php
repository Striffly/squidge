<?php

/**
 * DeliveryTab
 *
 * The delivery tab of the options page: serving
 * the WebP and AVIF files, its test, the rules of
 * the server and the helper of the themes.
 *
 * @package     OIO
 * @author      Clément Gayot
 * @author      Ainsley Clark (Squidge, original author)
 * @category    Class
 * @repo        https://github.com/Striffly/octopus-image-optimizer
 *
 */

namespace Octopus\Admin\Tabs;

use Octopus\Admin\View;
use Octopus\Delivery\Htaccess;
use Octopus\Encoders\Formats;
use Octopus\Media\Status;

if (!defined('ABSPATH')) {
	exit; // Exit if accessed directly
}

class DeliveryTab extends View
{
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
	public function render($display, $formats, $counts)
	{
		$server = Htaccess::server();
		$names = ['apache' => 'Apache', 'nginx' => 'nginx', 'other' => __('Your server', 'octopus-image-optimizer')];
		$made = Status::with_versions();
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
						<?php foreach (array_keys(Formats::variants()) as $id) : ?>
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
		<?php if ($server !== 'nginx') : ?>
			<details class="octopus-fold">
				<summary class="octopus-fold__summary">
					<span class="octopus-fold__title"><?php esc_html_e('Rewrite rules for Apache and LiteSpeed', 'octopus-image-optimizer'); ?></span>
					<?php if ($server === 'apache') : ?>
						<?php $written = Htaccess::rules_written(); ?>
						<?php $this->render_badge($written ? 'on' : 'muted', $written ? __('Written in uploads/.htaccess', 'octopus-image-optimizer') : __('Not written', 'octopus-image-optimizer'), 'octopus-delivery-rules-state'); ?>
					<?php endif; ?>
				</summary>
				<div class="octopus-fold__content">
					<p class="octopus-fold__text">
						<?php
						$this->printf_html(
							$server === 'apache'
								/* translators: %s: uploads/.htaccess */
								? __('OIO writes them in %s when the server option is on, and removes them when it is off.', 'octopus-image-optimizer')
								/* translators: %s: uploads/.htaccess */
								: __('In %s, written by OIO when the server option is on.', 'octopus-image-optimizer'),
							'<code>uploads/.htaccess</code>'
						);
						?>
					</p>
					<?php $this->render_code(implode("\n", Htaccess::apache_rules()), 'octopus-code-apache'); ?>
				</div>
			</details>
		<?php endif; ?>
		<?php if ($server !== 'apache') : ?>
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
}
