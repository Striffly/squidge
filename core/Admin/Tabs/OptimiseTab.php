<?php

/**
 * OptimiseTab
 *
 * The optimise tab of the options page: the jobs
 * optimising the media library.
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
use Octopus\Media\Status;
use Octopus\Settings;

if (!defined('ABSPATH')) {
	exit; // Exit if accessed directly
}

class OptimiseTab extends View
{
	/**
	 * Renders the optimisation of the media library:
	 * the new images, the outdated ones, or all.
	 *
	 * @param array $counts
	 * @param bool $jobs
	 * @since 1.0.0
	 */
	public function render($counts, $jobs)
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
}
