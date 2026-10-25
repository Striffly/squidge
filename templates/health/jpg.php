<?php

/**
 * jpg
 *
 * JPG installation instructions, shown
 * when jpegoptim is not installed.
 *
 * @package     Squidge
 * @version     0.1.4
 * @author      Ainsley Clark
 * @category    Templates
 * @repo        https://github.com/ainsleyclark/squidge
 *
 */

if (!defined('ABSPATH')) {
	exit; // Exit if accessed directly
}

?>

<!-- =====================
	JPG
	===================== -->
<ul class="squidge-install__list">
	<li>
		<span class="squidge-install__os">Linux</span>
		<code>sudo apt-get install jpegoptim</code>
		<a href="https://vitux.com/optimize-jpeg-jpg-images-in-ubuntu-with-jpegoptim/" target="_blank" rel="noopener">More information</a>
	</li>
	<li>
		<span class="squidge-install__os">Mac</span>
		<code>brew install jpegoptim</code>
		<a href="https://formulae.brew.sh/formula/jpegoptim" target="_blank" rel="noopener">More information</a>
	</li>
	<li>
		<span class="squidge-install__os">Windows</span>
		<a href="https://github.com/XhmikosR/jpegoptim-windows" target="_blank" rel="noopener">Download for Windows</a>
	</li>
</ul>
