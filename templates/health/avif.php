<?php

/**
 * avif
 *
 * AVIF installation instructions, shown
 * when avifenc is not installed.
 *
 * @package     OIO
 * @author      Clément Gayot
 * @author      Ainsley Clark (Squidge, original author)
 * @category    Templates
 * @repo        https://github.com/Striffly/octopus-image-optimizer
 *
 */

if (!defined('ABSPATH')) {
	exit; // Exit if accessed directly
}

?>

<!-- =====================
	AVIF
	===================== -->
<ul class="octopus-install__list">
	<li>
		<span class="octopus-install__os">Linux</span>
		<?php esc_html_e('Depending on your distribution, you may need to build it from source.', 'octopus-image-optimizer'); ?>
		<a href="https://aomediacodec.github.io/av1-avif/" target="_blank" rel="noopener"><?php esc_html_e('More information', 'octopus-image-optimizer'); ?></a>
	</li>
	<li>
		<span class="octopus-install__os">Mac</span>
		<?php $this->render_command('brew install libavif'); ?>
		<a href="https://formulae.brew.sh/formula/libavif" target="_blank" rel="noopener"><?php esc_html_e('More information', 'octopus-image-optimizer'); ?></a>
	</li>
	<li>
		<span class="octopus-install__os">Windows</span>
		<a href="https://github.com/AOMediaCodec/libavif/releases" target="_blank" rel="noopener"><?php esc_html_e('Download for Windows', 'octopus-image-optimizer'); ?></a>
	</li>
</ul>
