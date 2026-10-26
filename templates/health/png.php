<?php

/**
 * png
 *
 * PNG installation instructions: optipng, or
 * oxipng (faster) which replaces it when
 * installed, and pngquant for the lossy
 * compression.
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
	PNG
	===================== -->
<ul class="octopus-install__list">
	<li>
		<span class="octopus-install__os">Linux</span>
		<code>sudo apt install optipng pngquant</code>
		<a href="https://packages.debian.org/search?keywords=pngquant" target="_blank" rel="noopener">More information</a>
	</li>
	<li>
		<span class="octopus-install__os">Faster</span>
		<code>sudo apt install oxipng</code>
		<span>replaces optipng, on recent systems, or from its</span>
		<a href="https://github.com/oxipng/oxipng/releases" target="_blank" rel="noopener">releases</a>
	</li>
	<li>
		<span class="octopus-install__os">Mac</span>
		<code>brew install oxipng pngquant</code>
		<a href="https://formulae.brew.sh/formula/oxipng" target="_blank" rel="noopener">More information</a>
	</li>
</ul>
