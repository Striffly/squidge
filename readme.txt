=== OIO – Octopus Image Optimizer ===
Contributors: striffly
Donate link: https://github.com/Striffly/octopus-image-optimizer#-support
Tags: webp, avif, image optimization, compress images, performance
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 8.2
Stable tag: 1.0.0
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Squeezes images. Spills no ink, sends no bill. JPG, PNG, WebP and AVIF optimised on your own server, free and unlimited.

== Description ==

[GitHub](https://github.com/Striffly/octopus-image-optimizer) | [Documentation](https://github.com/Striffly/octopus-image-optimizer#readme) | [Changelog](https://github.com/Striffly/octopus-image-optimizer/blob/main/CHANGELOG.md) | [Support](https://github.com/Striffly/octopus-image-optimizer/issues)

OIO optimises the images of your media library with the libraries of your own server: `jpegoptim`, `optipng` or `oxipng`, `pngquant`, `cwebp` and `avifenc`, or GD and Imagick for the WebP and AVIF formats. Free and unlimited, no API key, no quota: your images never leave your server.

= Optimisation =

* Compresses JPG and PNG images in place, and creates their WebP and AVIF versions, kept only when lighter.
* Keeps the original images, to restore them at any time.
* Strips the metadata (camera, date, GPS position), but the colour profile the colours need.
* Handles CMYK JPGs and the photos stored sideways.

= Media library =

* Optimises the images on upload, or the whole library in the background, with pause and cancel.
* Re-optimises the images left behind by new settings or new image sizes of the theme.
* Shows the status, the errors and the bytes saved of each image, and optimises or restores it in one click.

= On the site =

* Serves AVIF and WebP with the JPG or PNG as fallback: in a `<picture>` element, or from the server with rewrite rules (Apache, nginx).
* Tests the delivery.

= For developers =

* `octopus_image()` and `octopus_image_sources()` give the `<picture>` of an image or its srcsets, in your own templates.
* WP-CLI: `wp octopus run`, `clean`, `restore`, `stats` and `health`.
* Filters for the folder of the originals, the priority and threads of the libraries, the pace of the background jobs and the path of a library.

All of them are documented in the [README on GitHub](https://github.com/Striffly/octopus-image-optimizer#-developers).

== Installation ==

OIO needs PHP allowed to run the libraries of the server (`proc_open()` or `exec()`):

* JPG: `jpegoptim`. Without it, not compressed.
* PNG: `optipng` or `oxipng` (faster). Without them, not compressed.
* PNG lossy (optional): `pngquant`. Without it, lossless only.
* WebP: `cwebp`. Without it, GD or Imagick, with heavier PNG graphics.
* AVIF: `avifenc`. Without it, GD or Imagick (WordPress 6.5+), with less sharp colours.

On Debian or Ubuntu: `sudo apt install jpegoptim optipng webp libavif-bin`.

On Windows: no JPG and PNG compression. For WebP and AVIF, put the official builds of [`cwebp`](https://developers.google.com/speed/webp/download) and [`avifenc`](https://github.com/AOMediaCodec/libavif/releases) on the `PATH`, or set their path with the `octopus_binary_path` filter.

1. Download the zip of the latest [release](https://github.com/Striffly/octopus-image-optimizer/releases).
2. Upload it in `Plugins > Add New > Upload Plugin`, and activate it.

After installing:

* **The new uploads** are optimised right away: `Optimise new uploads`, ticked by default.
* **The images already in the library** wait in the Optimise tab: `Optimise new images` optimises them in the background.
* **The Overview tab** lists the missing libraries, and how to install them.
* **WebP and AVIF are not served** until you turn them on in the Delivery tab.

== Frequently Asked Questions ==

= Does OIO replace my images? =

By default, yes: the JPG and PNG files are replaced by their compressed version, and the originals are kept in `uploads/octopus/originals-{key}/`. `Restore original images` on the settings page, or `Restore original` on an image of the media library, puts them back. To only make the WebP and AVIF versions, untick `Compress JPG images` and `Compress PNG images` on the Settings tab.

= Does it serve WebP and AVIF automatically? =

Once turned on in the Delivery tab, in one of two ways:

* **In the HTML**: the images of the content and of `wp_get_attachment_image()` (featured images, galleries, most themes) are wrapped in a `<picture>` element, with the JPG or PNG as fallback. Works everywhere, but may break a layout styling `img` directly. In a custom theme, use [`octopus_image()`](https://github.com/Striffly/octopus-image-optimizer#theme-development).
* **From the server**: the server answers `photo.jpg` with its AVIF or WebP version when the browser reads it. The HTML does not change, and CSS backgrounds are served too. OIO writes the rules in `uploads/.htaccess` on Apache and LiteSpeed; on nginx, add the ones given on the Delivery tab, or in the [README](https://github.com/Striffly/octopus-image-optimizer#-faq).

A CDN must vary its cache on the `Accept` header, or serve the JPG to everyone. `Test the delivery` checks what the server sends.

= What happens when the theme changes its image sizes? =

Its images are listed as "Older settings": `Re-optimise outdated images` makes their thumbnails again from the originals, and optimises them.

= Does it slow the site down? =

The background jobs run the libraries at the lowest priority, behind the requests of the site. On a small server, the [filters](https://github.com/Striffly/octopus-image-optimizer#filters) slow them down further.

= Does it work on shared hosting? =

Yes for WebP and AVIF, made by GD or Imagick when the libraries are missing. The JPG and PNG compression needs the libraries, which most shared hosts do not let you install: ask your host. The Overview tab tells what is missing.

= What happens when I deactivate or delete OIO? =

Deactivated, OIO stops its background job and removes its rewrite rules: the site serves the JPG and PNG images again. Its settings and files stay, and the rules come back on activation.

Deleted, OIO removes its settings and data from the database, but leaves the files: the compressed images, their WebP and AVIF versions and the originals. To get the originals back, run `Restore original images` before deleting it.

Before deactivating, a dialog shows how many images OIO has optimised and the size of `uploads/octopus/`, with a button to restore the originals, and warns when the theme calls `octopus_image()`: its pages break while OIO is deactivated. With nothing to warn about, OIO deactivates at once.

== Screenshots ==

1. The overview: the bytes saved, the status of the images and the libraries of each format.
2. The optimisation of the media library in the background, with its progress.
3. The delivery of WebP and AVIF on the site, and its test.
4. The settings of each format: quality, compression, library.
5. The status and the bytes saved of each image, in the media library.

== Privacy ==

* **No external request**: no API, no tracking. The delivery test only requests the images of the site, from its CDN if they are served by one.
* **No metadata**: the optimised images lose it, GPS position included. The originals keep it.
* **Private originals**: they are kept in `uploads/octopus/originals-{key}/`, the key drawn at random for each site, so their URL cannot be guessed. A `.htaccess` denies the web access to that folder on Apache and LiteSpeed. On nginx, add a `location ^~ /wp-content/uploads/octopus/originals- { deny all; }` rule to the `server` block of the site (also given on the Delivery tab).
* **Originals out of the web root**: set their folder with the `octopus_backup_dir` filter, before optimising any image. OIO does not move the originals already kept: move the folder yourself when you change it.

== Changelog ==

= 1.0.0 =
* Renamed to OIO – Octopus Image Optimizer, starting again from Squidge 0.1.4: no migration of its data.
* The original images are kept in `uploads/octopus/originals-{key}/`, a folder whose URL cannot be guessed, and every optimisation starts again from them.
* WebP and AVIF files keep the extension of their source (`image.jpg.webp`), in `uploads/octopus/formats/`, and can be served on the site with `<picture>` or rewrite rules.
* Background optimisation of the media library, with pause and cancel, and re-optimisation when the settings or the thumbnail sizes change.
* Clean up: remove the WebP and AVIF files, or restore the original images.
* Status column in the media library and readable error notices.
* CMYK JPG images, AVIF quality settings, CPU limits adjustable by filters.
* Colour profiles kept in every format when the colours need them (Display P3, Adobe RGB).
* WebP and AVIF versions of the photos stored sideways turned the right way up, even where WordPress did not turn them.
* Requires WordPress 6.2 and PHP 8.2.

The versions of Squidge, before OIO, are in the [full changelog](https://github.com/Striffly/octopus-image-optimizer/blob/main/CHANGELOG.md).

== Credits ==

OIO is developed by [Clément Gayot](https://github.com/Striffly).

It started as a fork of [Squidge](https://github.com/ainsleyclark/squidge), created by [Ainsley Clark](https://github.com/ainsleyclark) in 2021 with contributions from Roman K, Rohinish and karbivskij, and was largely rewritten in 2026. Many thanks to them for the foundations: the idea of relying on the native libraries of the server comes from Squidge.

Thanks also to [Media Optimizer](https://wordpress.org/plugins/yoohw-media-optimizer/) by YooHW for some ideas: the menu, the delivery page, the display of the bytes saved, and more.
