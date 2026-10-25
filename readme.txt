=== Squidge ===
Contributors: ainsleyclark
Donate link: https://www.paypal.com/donate?hosted_button_id=PQG5A34KWUXRJ
Tags: image, compression, plugin, image compression, webp, lazy load, optimization, seo, lossless, lossy, jpg, png, avif, convert
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.1.4
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Squidge is a FREE WordpPress Plugin built for developers in mind compressing and convert images using jpegoptim,
optipng, cwebp, and libavif. It's extremley simple to use and is designed to increase PSI and boost rankings.

== Description ==

## Why?

Image compression in WordPress can be costly, confusing and sometimes just simply don't work. We have aimed to simplify
the process by requiring the developer to install the required package on the operating system and Squidge does the
hard work for you.

- **IT'S FREE**
- Unlimited file size, no limits.
- Optimised for your site to rank.
- CLI to regenerate all of your media files.
- Helper functions to output images in templates.
- Uses the native `cwebp` and `libavif` libraries.

## What does Squidge do?

Squidge compresses and converts image files when the user has uploaded a file to WordPress.
The original images are kept in `uploads/squidge-originals/` (same tree as the uploads folder), and every
optimisation starts again from them.

- Compresses JPG images in place using `jpegoptim`.
- Compresses PNG images in place using `optipng`.
- Converts JPG and PNG images to `.webp` files using `cwebp` with the appended extension  e.g. `image.jpg.webp`.
- Converts JPG and PNG images to `.avif` files using `libavif` with the appended extension  e.g. `image.jpg.avif`.

== Installation ==

1. Go to the [releases](https://github.com/ainsleyclark/squidge/releases) section and download the plugin.
2. Upload the `squidge` plugin to your `/wp-content/plugins/` directory.
3. Activate the plugin through the "Plugins" menu in WordPress.
4. Check the Settings tab under `Settings | Squidge Options` to ensure the libraries are installed, if they aren't,
run the commands listed dependent on your operating system.
5. Check the individual optimisation tabs and adjust settings accordingly.
6. Done!

== Render Images ==

The compressed JPG and PNG files replace the originals, so your whole site uses them. There are two ways to also
offer the AVIF and WebP versions.

**Automatically**: on the `Overview` tab of the settings, tick `Use modern formats (WebP, AVIF)`. Images of the post
content and images rendered by `wp_get_attachment_image()` (featured images, galleries, most themes) are then wrapped
in a `<picture>` element with AVIF and WebP sources. This option is off by default.

**In templates**: the `squidge_image` helper function always returns a `<picture>` element with the AVIF and WebP
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
function squidge_image($image_id, $class = '', $lazy = false)
```

= Output =
```html
<picture class="squidge-picture hero">
	<source type="image/avif" srcset="/sample-image.jpg.avif 2500w, /sample-image-300x200.jpg.avif 300w" sizes="(max-width: 2500px) 100vw, 2500px">
	<source type="image/webp" srcset="/sample-image.jpg.webp 2500w, /sample-image-300x200.jpg.webp 300w" sizes="(max-width: 2500px) 100vw, 2500px">
	<img data-squidge src="/sample-image.jpg" srcset="/sample-image.jpg 2500w, /sample-image-300x200.jpg 300w" sizes="(max-width: 2500px) 100vw, 2500px" width="2500" height="1667" alt="Alt text">
</picture>
```

== Screenshots ==

1. Plugin settings page
2. Example compression tab

== Changelog ==

= 0.1.0 =
* Initial Release

== Credits ==

Written by [Ainsley Clark](https://github.com/ainsleyclark)
