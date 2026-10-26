<?php

/**
 * jpg
 *
 * JPG installation instructions, shown
 * when jpegoptim is not installed.
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
	JPG
	===================== -->
<ul class="octopus-install__list">
	<li>
		<span class="octopus-install__os">Linux</span>
		<code>sudo apt-get install jpegoptim</code>
		<a href="https://vitux.com/optimize-jpeg-jpg-images-in-ubuntu-with-jpegoptim/" target="_blank" rel="noopener"><?php esc_html_e('More information', 'octopus-image-optimizer'); ?></a>
	</li>
	<li>
		<span class="octopus-install__os">Mac</span>
		<code>brew install jpegoptim</code>
		<a href="https://formulae.brew.sh/formula/jpegoptim" target="_blank" rel="noopener"><?php esc_html_e('More information', 'octopus-image-optimizer'); ?></a>
	</li>
	<li>
		<span class="octopus-install__os">Windows</span>
		<a href="https://github.com/XhmikosR/jpegoptim-windows" target="_blank" rel="noopener"><?php esc_html_e('Download for Windows', 'octopus-image-optimizer'); ?></a>
	</li>
</ul>
