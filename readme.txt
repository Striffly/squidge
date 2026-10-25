=== OIO – Octopus Image Optimizer ===
Contributors: striffly
Tags: image, compression, plugin, image compression, webp, lazy load, optimization, seo, lossless, lossy, jpg, png, avif, convert
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.1.4
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Squeezes images. Spills no ink, sends no bill. JPG, PNG, WebP and AVIF optimised on your own server, free and unlimited.

== Description ==

## Why?

Image compression in WordPress can be costly, confusing and sometimes just simply don't work. We have aimed to simplify
the process by requiring the developer to install the required package on the operating system and OIO does the
hard work for you.

- **IT'S FREE**
- Unlimited file size, no limits.
- Optimised for your site to rank.
- CLI to regenerate all of your media files.
- Helper functions to output images in templates.
- Uses the native `cwebp` and `libavif` libraries.

## What does OIO do?

OIO compresses and converts image files when the user has uploaded a file to WordPress.
The original images are kept in `uploads/oio-originals/` (same tree as the uploads folder), and every
optimisation starts again from them.

- Compresses JPG images in place using `jpegoptim`.
- Compresses PNG images in place using `optipng`.
- Converts JPG and PNG images to `.webp` files using `cwebp` with the appended extension  e.g. `image.jpg.webp`.
- Converts JPG and PNG images to `.avif` files using `libavif` with the appended extension  e.g. `image.jpg.avif`.

== Installation ==

1. Go to the [releases](https://github.com/Striffly/octopus-image-optimizer/releases) section and download the plugin.
2. Upload the `octopus-image-optimizer` plugin to your `/wp-content/plugins/` directory.
3. Activate the plugin through the "Plugins" menu in WordPress.
4. Check the Settings tab under `Settings | OIO` to ensure the libraries are installed, if they aren't,
run the commands listed dependent on your operating system.
5. Check the individual optimisation tabs and adjust settings accordingly.
6. Done!

== Render Images ==

The compressed JPG and PNG files replace the originals, so your whole site uses them. There are two ways to also
offer the AVIF and WebP versions.

**Automatically**: on the `Overview` tab of the settings, tick `Use modern formats (WebP, AVIF)`. Images of the post
content and images rendered by `wp_get_attachment_image()` (featured images, galleries, most themes) are then wrapped
in a `<picture>` element with AVIF and WebP sources. This option is off by default.

**In templates**: the `oio_image` helper function always returns a `<picture>` element with the AVIF and WebP
versions that exist on the file system, whatever this option.

= Function =
```php
/**
 * Returns a <picture> element for an image of the media library: AVIF
 * and WebP <source> elements (when their files exist), then the <img>
 * with the srcset and sizes computed by WordPress as the fallback.
 *
 * - If lazy is true, the data-src or data-srcset will be used instead.
 * - If a class is set, the class will be outputted on the <picture> element.
 *
 * @param $image_id
 * @param string $class
 * @param false $lazy
 * @return string
 */
function oio_image($image_id, $class = '', $lazy = false)
```

= Output =
```html
<picture class="oio-picture hero">
	<source type="image/avif" srcset="/sample-image.jpg.avif 2500w, /sample-image-300x200.jpg.avif 300w" sizes="(max-width: 2500px) 100vw, 2500px">
	<source type="image/webp" srcset="/sample-image.jpg.webp 2500w, /sample-image-300x200.jpg.webp 300w" sizes="(max-width: 2500px) 100vw, 2500px">
	<img data-oio src="/sample-image.jpg" srcset="/sample-image.jpg 2500w, /sample-image-300x200.jpg 300w" sizes="(max-width: 2500px) 100vw, 2500px" width="2500" height="1667" alt="Alt text">
</picture>
```

== Screenshots ==

1. Plugin settings page
2. Example compression tab

== Changelog ==

= 1.0.0 =
* Renamed to OIO – Octopus Image Optimizer, starting again from Squidge 0.1.4: no migration of its data.
* The original images are kept in `uploads/oio-originals/`, and every optimisation starts again from them.
* WebP and AVIF files keep the extension of their source (`image.jpg.webp`), and can be served on the site with `<picture>`.
* Background optimisation of the media library, with pause and cancel, and re-optimisation when the settings or the thumbnail sizes change.
* Clean up: remove the WebP and AVIF files, or restore the original images.
* Status column in the media library and readable error notices.
* CMYK JPG images, AVIF quality settings, CPU limits adjustable by filters.
* Requires WordPress 6.0 and PHP 7.4.

= 0.1.0 =
* Squidge, initial release.

== Credits ==

OIO is developed by [Clément Gayot](https://github.com/Striffly).

It started as a fork of [Squidge](https://github.com/ainsleyclark/squidge), created by [Ainsley Clark](https://github.com/ainsleyclark) in 2021 with contributions from Roman K, Rohinish and karbivskij, and was largely rewritten in 2026. Many thanks to them for the foundations: the idea of relying on the native libraries of the server comes from Squidge.
