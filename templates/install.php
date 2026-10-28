<?php

/**
 * install
 *
 * How to install the library of a format, by system:
 * its command, and where to learn more. Rendered by
 * View::render_install(), with $octopus_install
 * the format (jpg, png, webp, avif), or oxipng shown
 * while optipng compresses the PNG images.
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

$octopus_more = __('More information', 'octopus-image-optimizer');
$octopus_windows = __('Download for Windows', 'octopus-image-optimizer');
$octopus_steps = [
	'jpg' => [
		['os' => 'Linux', 'command' => 'sudo apt install jpegoptim', 'link' => ['https://vitux.com/optimize-jpeg-jpg-images-in-ubuntu-with-jpegoptim/', $octopus_more]],
		['os' => 'Mac', 'command' => 'brew install jpegoptim', 'link' => ['https://formulae.brew.sh/formula/jpegoptim', $octopus_more]],
		['os' => 'Windows', 'link' => ['https://github.com/XhmikosR/jpegoptim-windows', $octopus_windows]],
	],
	'png' => [
		['os' => 'Linux', 'command' => 'sudo apt install optipng pngquant', 'link' => ['https://packages.debian.org/search?keywords=pngquant', $octopus_more]],
		[
			'os' => __('Faster', 'octopus-image-optimizer'),
			'command' => 'sudo apt install oxipng',
			'html' => sprintf(
				/* translators: %s: the URL of the releases of oxipng. */
				__('replaces optipng, on recent systems, or from its <a href="%s" target="_blank" rel="noopener">releases</a>', 'octopus-image-optimizer'),
				'https://github.com/oxipng/oxipng/releases'
			),
		],
		['os' => 'Mac', 'command' => 'brew install oxipng pngquant', 'link' => ['https://formulae.brew.sh/formula/oxipng', $octopus_more]],
	],
	'oxipng' => [
		['os' => 'Linux', 'command' => 'sudo apt install oxipng', 'link' => ['https://github.com/oxipng/oxipng/releases', $octopus_more]],
		['os' => 'Mac', 'command' => 'brew install oxipng', 'link' => ['https://formulae.brew.sh/formula/oxipng', $octopus_more]],
	],
	'webp' => [
		['os' => 'Linux', 'command' => 'sudo apt install webp', 'link' => ['https://www.interserver.net/tips/kb/install-and-serve-webp-images-on-ubuntu/', $octopus_more]],
		['os' => 'Mac', 'command' => 'brew install webp', 'link' => ['https://formulae.brew.sh/formula/webp', $octopus_more]],
		['os' => 'Windows', 'link' => ['https://developers.google.com/speed/webp/download', $octopus_windows]],
	],
	'avif' => [
		['os' => 'Linux', 'command' => 'sudo apt install libavif-bin', 'link' => ['https://packages.debian.org/search?keywords=libavif-bin', $octopus_more]],
		['os' => 'Mac', 'command' => 'brew install libavif', 'link' => ['https://formulae.brew.sh/formula/libavif', $octopus_more]],
		['os' => 'Windows', 'link' => ['https://github.com/AOMediaCodec/libavif/releases', $octopus_windows]],
	],
];

?>
<ul class="octopus-install__list">
	<?php foreach ($octopus_steps[$octopus_install] as $octopus_step) : ?>
		<li>
			<span class="octopus-install__os"><?php echo esc_html($octopus_step['os']); ?></span>
			<?php if (isset($octopus_step['command'])) : ?>
				<?php $this->render_command($octopus_step['command']); ?>
			<?php endif; ?>
			<?php if (isset($octopus_step['html'])) : ?>
				<span><?php echo wp_kses($octopus_step['html'], ['a' => ['href' => true, 'target' => true, 'rel' => true]]); ?></span>
			<?php endif; ?>
			<?php if (isset($octopus_step['link'])) : ?>
				<a href="<?php echo esc_url($octopus_step['link'][0]); ?>" target="_blank" rel="noopener"><?php echo esc_html($octopus_step['link'][1]); ?></a>
			<?php endif; ?>
		</li>
	<?php endforeach; ?>
</ul>
