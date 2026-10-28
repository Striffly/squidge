<?php

/**
 * oxipng
 *
 * oxipng installation instructions, shown while
 * optipng compresses the PNG images: once
 * installed, oxipng replaces it.
 *
 * @package     OIO
 * @author      Clément Gayot
 * @category    Templates
 * @repo        https://github.com/Striffly/octopus-image-optimizer
 *
 */

if (!defined('ABSPATH')) {
	exit; // Exit if accessed directly
}

?>

<!-- =====================
	oxipng
	===================== -->
<ul class="octopus-install__list">
	<li>
		<span class="octopus-install__os">Linux</span>
		<?php $this->render_command('sudo apt install oxipng'); ?>
		<a href="https://github.com/oxipng/oxipng/releases" target="_blank" rel="noopener"><?php esc_html_e('More information', 'octopus-image-optimizer'); ?></a>
	</li>
	<li>
		<span class="octopus-install__os">Mac</span>
		<?php $this->render_command('brew install oxipng'); ?>
		<a href="https://formulae.brew.sh/formula/oxipng" target="_blank" rel="noopener"><?php esc_html_e('More information', 'octopus-image-optimizer'); ?></a>
	</li>
</ul>
