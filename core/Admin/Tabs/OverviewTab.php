<?php

/**
 * OverviewTab
 *
 * The overview tab of the options page: the bytes
 * saved, the images by status and the state of
 * each format.
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
use Octopus\Engines\Libraries;
use Octopus\Media\Stats;
use Octopus\Media\Status;

if (!defined('ABSPATH')) {
	exit; // Exit if accessed directly
}

class OverviewTab extends View
{
	/**
	 * Renders the overview: the bytes saved, the images
	 * by status and the state of each format.
	 *
	 * @param array $formats
	 * @param array $counts
	 * @since 1.0.0
	 */
	public function render($formats, $counts)
	{
		$savings = Stats::savings();
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
}
