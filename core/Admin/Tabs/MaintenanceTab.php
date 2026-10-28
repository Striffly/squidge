<?php

/**
 * MaintenanceTab
 *
 * The maintenance tab of the options page:
 * removing the WebP and AVIF files, restoring the
 * original images.
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
use Octopus\Media\Cleaner;

if (!defined('ABSPATH')) {
	exit; // Exit if accessed directly
}

class MaintenanceTab extends View
{
	/**
	 * Renders the maintenance: removing the WebP and
	 * AVIF files, restoring the original images. Each
	 * asks to tick a box first.
	 *
	 * @param bool $jobs
	 * @since 1.0.0
	 */
	public function render($jobs)
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
}
