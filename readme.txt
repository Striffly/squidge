=== OIO – Octopus Image Optimizer ===
Contributors: striffly
Tags: webp, avif, image optimization, compress images, performance
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 8.2
Stable tag: 0.1.4
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Squeezes images. Spills no ink, sends no bill. JPG, PNG, WebP and AVIF optimised on your own server, free and unlimited.

== Description ==

OIO optimises the images of your media library with the libraries of your own server: `jpegoptim`, `optipng` or `oxipng`, `pngquant`, `cwebp` and `avifenc`, or GD and Imagick for the WebP and AVIF formats. Free and unlimited, no API key, no quota: your images never leave your server.

= Optimisation =

* Compresses JPG and PNG images in place, and creates their WebP and AVIF versions next to them (`photo.jpg.webp`, `photo.jpg.avif`), kept only when lighter than the compressed image.
* Keeps the original images: every optimisation starts again from them, and they can be restored at any time.
* Strips the metadata (camera, date, GPS position) from every optimised file.
* Handles CMYK JPGs, which the WebP and AVIF encoders cannot read.

= Media library =

* Optimises the images on upload, scaled down to a maximum size (2560 pixels by default, as WordPress does).
* Optimises, re-optimises or cleans up the whole library in the background, with a progress bar, pause and cancel. The job goes on when the page is closed.
* Spots the images optimised with older settings, or before the theme changed its image sizes, and makes their thumbnails again from the originals, scaled to the current maximum size.
* Shows the status of each image in the media library, and its errors in plain words.
* Shows the bytes saved, on each image of the media library and on the whole library, with what serving WebP and AVIF would save, and the disk used by the originals and the WebP and AVIF files.

= On the site =

* Serves AVIF and WebP, with the JPG or PNG as fallback (optional): in a `<picture>` element, or from the server with rewrite rules (written for Apache, given for nginx), which leaves the HTML as it is.
* Tests the delivery: types sent by the server, rewrite, originals kept private.
* `octopus_image( $image_id, $class, $lazy )` returns a `<picture>` in your own templates.

= For developers =

* WP-CLI: `wp octopus run`, `clean`, `restore`, `stats` and `health`. `wp octopus run --regenerate` makes the thumbnails again from the originals first.
* Filters: `octopus_backup_dir` (folder of the originals), `octopus_nice`, `octopus_avif_jobs`, `octopus_webp_mt` and `octopus_oxipng_threads` (priority and threads of the libraries), `octopus_job_seconds_between_batches` and `octopus_job_default_time_limit` (pace of the background jobs), `octopus_binary_path` (path of a library installed out of the usual folders).

See the [README on GitHub](https://github.com/Striffly/octopus-image-optimizer) for the details.

== Installation ==

The JPG and PNG compression needs a server where PHP can run programs (`proc_open()` or `exec()`), with `jpegoptim` and `optipng` or `oxipng` installed: Linux and macOS have them, Windows has no official build of them (the WebP and AVIF work there with the official `cwebp` and `avifenc`). The WebP and AVIF formats work without their libraries, with GD or Imagick when they support them (AVIF since WordPress 6.5), but `cwebp` and `avifenc` are better. E.g. on Debian or Ubuntu: `sudo apt install jpegoptim optipng webp libavif-bin`. Each format works on its own: a missing library only turns its format off, or hands the WebP or AVIF to GD or Imagick. `oxipng`, faster, replaces `optipng` when installed, and `pngquant` adds an optional lossy compression of the PNG images.

1. Upload the plugin in `Plugins > Add New > Upload Plugin`, and activate it.
2. Open `Settings > OIO`: the Overview tab checks the libraries and shows how to install the missing ones.
3. Click `Optimise new images` to optimise the images already in the library.

== Frequently Asked Questions ==

= Does OIO replace my images? =

Yes, the JPG and PNG files are replaced by their compressed version, and their originals are kept in `uploads/octopus-originals/`. `Restore original images` on the settings page, or `Restore original` on an image of the media library, puts them back.

= Does it serve WebP and AVIF automatically? =

Once turned on, on the Delivery tab: in the HTML, where the images are wrapped in a `<picture>` element, or from the server, with rewrite rules answering `photo.jpg` with its AVIF or WebP version (OIO writes them on Apache and LiteSpeed, and gives them for nginx). The test on the same tab checks what the server sends.

= What happens when the theme changes its image sizes? =

The images optimised before are listed as "Older settings". `Re-optimise outdated images` makes their thumbnails again from the originals, and optimises them.

= Does it slow the site down? =

The background jobs run the libraries with the lowest priority: the requests of the site go first. Filters slow them down further on a small server.

= Does it work on shared hosting? =

Yes for the WebP and AVIF formats: without their libraries, or where PHP cannot run programs, GD or Imagick make them. The JPG and PNG compression needs the libraries installed and PHP allowed to run them: most shared hosts do not let you install them, ask your host. The Overview tab tells what is missing.

= Are the original images public? =

OIO writes a `.htaccess` denying the web access to `uploads/octopus-originals/`, which works with Apache and LiteSpeed. With nginx, add a `location ^~ /wp-content/uploads/octopus-originals/ { deny all; }` rule, or move the folder out of the web root with the `octopus_backup_dir` filter.

= What happens when I deactivate or delete OIO? =

Deactivated, OIO stops its background job and removes its rewrite rules from `uploads/.htaccess`: the site serves the JPG and PNG images again. Its settings and files stay, and the rules come back when it is activated again.

Deleted, its settings and data are removed from the database, and the files stay: the compressed images, their WebP and AVIF versions and the originals. To get your original images back, run `Restore original images` before deleting OIO, which reminds it when it is deactivated.

== Privacy ==

OIO sends nothing out of your server: no API, no tracking, no external request. The delivery test only requests the site itself. The optimised images lose their metadata, the originals keep them and are protected from the web access.

== Changelog ==

= 1.0.0 =
* Renamed to OIO – Octopus Image Optimizer, starting again from Squidge 0.1.4: no migration of its data.
* The original images are kept in `uploads/octopus-originals/`, and every optimisation starts again from them.
* WebP and AVIF files keep the extension of their source (`image.jpg.webp`), and can be served on the site with `<picture>`.
* Background optimisation of the media library, with pause and cancel, and re-optimisation when the settings or the thumbnail sizes change.
* Clean up: remove the WebP and AVIF files, or restore the original images.
* Status column in the media library and readable error notices.
* CMYK JPG images, AVIF quality settings, CPU limits adjustable by filters.
* Requires WordPress 6.0 and PHP 8.2.

= 0.1.4 =
* Squidge: updated how the compressed images are marked in the post meta.

= 0.1.3 =
* Squidge: marks the compressed images in the post meta, deployment to WordPress.org.

= 0.1.2 =
* Squidge: escaped shell arguments, only the supported libraries can be run.

= 0.1.1 =
* Squidge: settings link on the plugins page, readme fixes.

= 0.1.0 =
* Squidge, initial release.

== Credits ==

OIO is developed by [Clément Gayot](https://github.com/Striffly).

It started as a fork of [Squidge](https://github.com/ainsleyclark/squidge), created by [Ainsley Clark](https://github.com/ainsleyclark) in 2021 with contributions from Roman K, Rohinish and karbivskij, and was largely rewritten in 2026. Many thanks to them for the foundations: the idea of relying on the native libraries of the server comes from Squidge.
