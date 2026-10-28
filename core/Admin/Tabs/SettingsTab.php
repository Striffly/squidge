<?php

/**
 * SettingsTab
 *
 * The settings tab of the options page: the
 * uploads, then a card per format.
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
use Octopus\Encoders\Formats;
use Octopus\Engines\Libraries;

if (!defined('ABSPATH')) {
	exit; // Exit if accessed directly
}

class SettingsTab extends View
{
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
	public function render($general, $formats)
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
							<?php $this->render_toggle($switch, $format['fields'][$switch]['label']); ?>
						</div>
						<div class="octopus-format__engines">
							<?php $this->render_engine($format); ?>
						</div>
					</header>
					<p class="octopus-format__description"><?php echo wp_kses($format['fields'][$switch]['help'], ['strong' => [], 'code' => []]); ?></p>
					<?php if (!Libraries::can_execute()) : ?>
						<?php // Installing would not help: the overview says why. ?>
					<?php elseif (!$format['installed'] && $format['engine']) : ?>
						<?php
						/* translators: %s: a library, e.g. cwebp. */
						$this->render_install($id, sprintf(__('Recommended: install %s', 'octopus-image-optimizer'), $format['library']), sprintf($format['recommendation'], $format['engine']));
						?>
					<?php elseif (!$format['installed']) : ?>
						<?php
						/* translators: %s: a library, e.g. cwebp. */
						$this->render_install($id, sprintf(__('How to install %s', 'octopus-image-optimizer'), $format['library']));
						?>
					<?php elseif ($format['upgrade']) : ?>
						<?php
						/* translators: %s: a library, e.g. cwebp. */
						$this->render_install($format['preferred'], sprintf(__('Recommended: install %s', 'octopus-image-optimizer'), $format['preferred']), $format['preference']);
						?>
					<?php endif; ?>
					<details class="octopus-format__settings js-octopus-fold">
						<summary class="octopus-format__summary"><?php esc_html_e('Settings', 'octopus-image-optimizer'); ?></summary>
						<div class="octopus-format__fields octopus-fields">
							<?php foreach ($fields as $name => $field) : ?>
								<?php $this->render_field($name, $field + Formats::availability($name)); ?>
							<?php endforeach; ?>
							<?php foreach ($format['extras'] as $name => $installed) : ?>
								<?php if ($format['installed'] && !$installed) : ?>
									<?php
									/* translators: 1: a library, e.g. pngquant, 2: what it is for, e.g. lossy compression. */
									$this->render_install($id, sprintf(__('How to install %1$s, for the %2$s', 'octopus-image-optimizer'), $name, $format['uses'][$name]), '', true);
									?>
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
}
