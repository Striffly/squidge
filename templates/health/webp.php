<?php

/**
 * webp
 *
 * WebP installation instructions, shown
 * when cwebp is not installed.
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
	WebP
	===================== -->
<ul class="squidge-install__list">
	<li>
		<span class="squidge-install__os">Linux</span>
		<code>sudo apt-get install webp</code>
		<a href="https://www.interserver.net/tips/kb/install-and-serve-webp-images-on-ubuntu/" target="_blank" rel="noopener">More information</a>
	</li>
	<li>
		<span class="squidge-install__os">Mac</span>
		<code>brew install webp</code>
		<a href="https://formulae.brew.sh/formula/webp" target="_blank" rel="noopener">More information</a>
	</li>
	<li>
		<span class="squidge-install__os">Windows</span>
		<a href="https://developers.google.com/speed/webp/download" target="_blank" rel="noopener">Download for Windows</a>
	</li>
</ul>
