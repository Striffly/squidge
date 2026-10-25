<?php

/**
 * avif
 *
 * AVIF installation instructions, shown
 * when avifenc is not installed.
 *
 * @package     OIO
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
	AVIF
	===================== -->
<ul class="oio-install__list">
	<li>
		<span class="oio-install__os">Linux</span>
		Dependant on your distro, you may need to build from source.
		<a href="https://aomediacodec.github.io/av1-avif/" target="_blank" rel="noopener">More information</a>
	</li>
	<li>
		<span class="oio-install__os">Mac</span>
		<code>brew install libavif</code>
		<a href="https://formulae.brew.sh/formula/libavif" target="_blank" rel="noopener">More information</a>
	</li>
	<li>
		<span class="oio-install__os">Windows</span>
		<a href="https://github.com/AOMediaCodec/libavif/releases" target="_blank" rel="noopener">Download for Windows</a>
	</li>
</ul>
