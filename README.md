<p align="left">
    <img alt="OIO – Octopus Image Optimizer: squeezes images, spills no ink, sends no bill." src=".wordpress-org/banner-1544x500.png" width="772">
</p>

# OIO – Octopus Image Optimizer

🐙 **Squeezes images. Spills no ink, sends no bill.**

[![GNU General Public License 3.0](https://img.shields.io/github/license/Striffly/octopus-image-optimizer.svg)](https://www.gnu.org/licenses/gpl-3.0.en.html)
![WordPress 6.2 or later](https://img.shields.io/badge/WordPress-6.2%2B-3858e9)
![PHP 8.2 or later](https://img.shields.io/badge/PHP-8.2%2B-777bb4)

[Features](#-features) · [Screenshots](#-screenshots) · [Requirements](#-requirements) · [Installation](#-installation) ·
[Privacy](#-privacy) · [FAQ](#-faq) · [Developers](#-developers) · [Changelog](CHANGELOG.md) ·
[Contributing](CONTRIBUTING.md) · [Support](#-support)

OIO optimises the images of your WordPress media library with the libraries of your own server: `jpegoptim`,
`optipng` or `oxipng`, `pngquant`, `cwebp` and `avifenc`, or GD and Imagick for the WebP and AVIF formats. Free and
unlimited, no API key, no quota: your images never leave your server.

## ✨ Features

**Optimisation**

- Compresses JPG and PNG images in place, and creates their WebP and AVIF versions, kept only when lighter.
- Keeps the original images, to restore them at any time.
- Strips the metadata (camera, date, GPS position), but the colour profile the colours need.
- Handles CMYK JPGs and the photos stored sideways.

**Media library**

- Optimises the images on upload, or the whole library in the background, with pause and cancel.
- Re-optimises the images left behind by new settings or new image sizes of the theme.
- Shows the status, the errors and the bytes saved of each image, and optimises or restores it in one click.

**On the site**

- Serves AVIF and WebP with the JPG or PNG as fallback: in a `<picture>` element, or from the server with rewrite
  rules (Apache, nginx).
- Tests the delivery.

**For developers**

- `octopus_image()` and `octopus_image_sources()` give the `<picture>` of an image or its srcsets, in your own
  templates.
- WP-CLI: `wp octopus run`, `clean`, `restore`, `stats` and `health`.
- Filters for the folder of the originals, the priority and threads of the libraries, the pace of the background
  jobs and the path of a library.

All of them are documented in [Developers](#-developers).

## 📸 Screenshots

![The overview: the bytes saved, the status of the images and the libraries of each format.](.wordpress-org/screenshot-1.png)

<details>
<summary>The optimisation, the delivery, the settings and the media library</summary>

![The optimisation of the media library in the background, with its progress.](.wordpress-org/screenshot-2.png)

![The delivery of WebP and AVIF on the site, and its test.](.wordpress-org/screenshot-3.png)

![The settings of each format: quality, compression, library.](.wordpress-org/screenshot-4.png)

![The status and the bytes saved of each image, in the media library.](.wordpress-org/screenshot-5.png)

</details>

## 📋 Requirements

WordPress 6.2 and PHP 8.2 or later, and PHP allowed to run the libraries of the server (`proc_open()` or `exec()`):

| Format               | Library                        | Without it                                              |
|----------------------|--------------------------------|---------------------------------------------------------|
| JPG                  | `jpegoptim`                    | Not compressed                                          |
| PNG                  | `optipng` or `oxipng` (faster) | Not compressed                                          |
| PNG lossy (optional) | `pngquant`                     | Lossless only                                           |
| WebP                 | `cwebp`                        | GD or Imagick, with heavier PNG graphics                |
| AVIF                 | `avifenc`                      | GD or Imagick (WordPress 6.5+), with less sharp colours |

- On Debian or Ubuntu: `sudo apt install jpegoptim optipng webp libavif-bin`.
- On Windows: no JPG and PNG compression. For WebP and AVIF, put the official builds of
  [`cwebp`](https://developers.google.com/speed/webp/download) and
  [`avifenc`](https://github.com/AOMediaCodec/libavif/releases) on the `PATH`, or set their path with the
  `octopus_binary_path` filter.

## 📦 Installation

1. Download the zip of the latest [release](https://github.com/Striffly/octopus-image-optimizer/releases).
2. Upload it in `Plugins > Add New > Upload Plugin`, and activate it.

After installing:

- **The new uploads** are optimised right away: `Optimise new uploads`, ticked by default.
- **The images already in the library** wait in the Optimise tab: `Optimise new images` optimises them in the
  background.
- **The Overview tab** lists the missing libraries, and how to install them.
- **WebP and AVIF are not served** until you turn them on in the Delivery tab.

### Build from source

With [Composer](https://getcomposer.org/):

```bash
git clone https://github.com/Striffly/octopus-image-optimizer.git
cd octopus-image-optimizer
composer install # The dependencies, in vendor-prefixed/.
```

## 🔒 Privacy

- **No external request**: no API, no tracking. The delivery test only requests the images of the site, from its CDN
  if they are served by one.
- **No metadata**: the optimised images lose it, GPS position included. The originals keep it.
- **Private originals**: they are kept in `uploads/octopus/originals-{key}/`, the key drawn at random for each site,
  so their URL cannot be guessed. A `.htaccess` denies the web access to that folder on Apache and LiteSpeed. On
  nginx, add this rule to the `server` block of the site (also given on the Delivery tab):

  ```nginx
  location ^~ /wp-content/uploads/octopus/originals- {
  	deny all;
  }
  ```

- **Originals out of the web root**: set their folder with the `octopus_backup_dir` filter, before optimising any
  image. OIO does not move the originals already kept: move the folder yourself when you change it.

  ```php
  // e.g. next to the web root of a Bedrock site.
  add_filter('octopus_backup_dir', function () {
  	return dirname(ABSPATH, 2) . '/octopus-originals';
  });
  ```

## ❓ FAQ

### Does OIO replace my images?

By default, yes: the JPG and PNG files are replaced by their compressed version, and the originals are kept in
`uploads/octopus/originals-{key}/`. `Restore original images` on the settings page, or `Restore original` on an image
of the media library, puts them back. To only make the WebP and AVIF versions, untick `Compress JPG images` and
`Compress PNG images` on the Settings tab.

### Does it serve WebP and AVIF automatically?

Once turned on in the Delivery tab, in one of two ways:

- **In the HTML**: the images of the content and of `wp_get_attachment_image()` (featured images, galleries, most
  themes) are wrapped in a `<picture>` element, with the JPG or PNG as fallback. Works everywhere, but may break a
  layout styling `img` directly. In a custom theme, use [`octopus_image()`](#theme-development).
- **From the server**: the server answers `photo.jpg` with its AVIF or WebP version when the browser reads it. The
  HTML does not change, and CSS backgrounds are served too. OIO writes the rules in `uploads/.htaccess` on Apache and
  LiteSpeed; on nginx, add these ones:

```nginx
# In the http block:
map $http_accept $octopus_avif {
	default "";
	"~image/avif" ".avif";
}
map $http_accept $octopus_webp {
	default "";
	"~image/webp" ".webp";
}

# In the server block:
location ~* ^/wp-content/uploads/(?<octopus_file>.+\.(?:jpe?g|png))$ {
	add_header Vary Accept;
	try_files /wp-content/uploads/octopus/formats/$octopus_file$octopus_avif /wp-content/uploads/octopus/formats/$octopus_file$octopus_webp $uri =404;
}
```

A CDN must vary its cache on the `Accept` header, or serve the JPG to everyone. `Test the delivery` checks what the
server sends.

### What happens when the theme changes its image sizes?

Its images are listed as "Older settings": `Re-optimise outdated images` makes their thumbnails again from the
originals, and optimises them.

### Does it slow the site down?

The background jobs run the libraries at the lowest priority, behind the requests of the site. On a small server,
the [filters](#filters) slow them down further.

### Does it work on shared hosting?

Yes for WebP and AVIF, made by GD or Imagick when the libraries are missing. The JPG and PNG compression needs the
libraries, which most shared hosts do not let you install: ask your host. The Overview tab tells what is missing.

### What happens when I deactivate or delete OIO?

Deactivated, OIO stops its background job and removes its rewrite rules: the site serves the JPG and PNG images
again. Its settings and files stay, and the rules come back on activation.

Deleted, OIO removes its settings and data from the database, but leaves the files: the compressed images, their
WebP and AVIF versions and the originals. To get the originals back, run `Restore original images` before deleting
it.

Before deactivating, a dialog shows how many images OIO has optimised and the size of `uploads/octopus/`, with a
button to restore the originals, and warns when the theme calls `octopus_image()`: its pages break while OIO is
deactivated. With nothing to warn about, OIO deactivates at once.

### Which settings do the libraries use?

Every optimisation starts from the original image:

```bash
# JPG
jpegoptim --strip-all --all-progressive --overwrite --max={quality} photo.jpg

# PNG: optipng, or oxipng when installed
optipng -clobber -strip all -o {level} \
	-out photo.png octopus/originals-{key}/photo.png
oxipng -o {level} --strip safe --force --threads {threads} \
	--out photo.png octopus/originals-{key}/photo.png

# PNG, lossy compression: pngquant, then optipng or oxipng on tmp.png
pngquant --quality={quality - 15}-{quality} --strip --skip-if-larger --force \
	--output tmp.png -- octopus/originals-{key}/photo.png

# WebP
cwebp -q {quality} photo.jpg -o octopus/formats/photo.jpg.webp

# AVIF
avifenc -q {quality} --ignore-exif --ignore-xmp --speed {speed} --jobs {threads} \
	photo.jpg octopus/formats/photo.jpg.avif
```

- `avifenc` older than 1.0 gets the quality as `cq-level`. Its speed is 6 by default.
- The libraries run on several files of an image at once, within `octopus_threads` (4 on upload, 2 in background
  jobs): `avifenc` and `oxipng` get the threads left, `{threads}`.
- A PNG is also tried with `cwebp -near_lossless 60`, kept if lighter: graphics and screenshots get much lighter and
  sharper, photos stay lossy.
- Without `cwebp` or `avifenc`, GD or Imagick make the WebP at the same quality, and the AVIF at the quality + 5 (up
  to 89, from 90 as is), close to `avifenc`. The PNG images are not tried in near-lossless then.

## 🔧 Developers

### Theme development

The `<picture>` option only covers the content and `wp_get_attachment_image()`. For the images of your own markup,
e.g. from an ACF field, use these functions.

> [!IMPORTANT]
> Keep the `function_exists()` check: without it, the pages break while OIO is deactivated.

**`octopus_image()`** returns the `<picture>` of an image, whatever the options:

```php
if (function_exists('octopus_image')) {
	echo octopus_image($image_id, 'hero');       // Class of the <picture> element.
	echo octopus_image($image_id, 'hero', true); // data-src and data-srcset, for a JavaScript lazy loader.
}
```

```html
<picture class="octopus-picture hero">
	<source type="image/avif" srcset="octopus/formats/photo.jpg.avif 2500w, …" sizes="…">
	<source type="image/webp" srcset="octopus/formats/photo.jpg.webp 2500w, …" sizes="…">
	<img data-octopus src="photo.jpg" srcset="photo.jpg 2500w, …" sizes="…" width="2500" height="1667" alt="">
</picture>
```

**`octopus_image_sources()`** returns the sources only, for a theme writing its own `<picture>`: from a srcset or an
image URL, the srcset of each format, keyed by MIME type, best first. A size without a file in a format keeps its JPG
or PNG.

```php
$sources = function_exists('octopus_image_sources') ? octopus_image_sources($srcset) : [];
// ['image/avif' => 'octopus/formats/photo.jpg.avif 2500w, photo-300x200.jpg 300w',
//  'image/webp' => 'octopus/formats/photo.jpg.webp 2500w, octopus/formats/photo-300x200.jpg.webp 300w']

foreach ($sources as $type => $format_srcset) {
	printf('<source type="%s" srcset="%s" sizes="%s">', esc_attr($type), esc_attr($format_srcset), esc_attr($sizes));
}
```

### WP-CLI

| Command              | What it does                                                                         |
|----------------------|--------------------------------------------------------------------------------------|
| `wp octopus health`  | Checks that the libraries are installed.                                             |
| `wp octopus run`     | Optimises the new images, with the settings of the settings page.                    |
| `wp octopus clean`   | Removes the WebP and AVIF files. The JPG and PNG files stay compressed.              |
| `wp octopus restore` | Restores the original images and removes every file made by OIO.                     |
| `wp octopus stats`   | Prints the bytes saved on the media library. `--rebuild` measures every image again. |
| `wp octopus version` | Shows the version of OIO.                                                            |

`wp octopus run` takes these options, all listed by `wp help octopus run`:

```bash
wp octopus run                   # Optimises the new images.
wp octopus run --force           # Optimises every image again.
wp octopus run --regenerate      # Makes the thumbnails again from the originals first, implies --force.
wp octopus run --thumbnails-only # Leaves the full size images out.

# Turns a format off or on: --[no-]jpg, --[no-]png, --[no-]webp, --[no-]avif.
wp octopus run --no-avif

# Overrides a setting of the settings page: --jpg-quality, --png-compression, --png-level,
# --png-lossy-quality, --webp-quality, --avif-quality, --avif-speed.
wp octopus run --webp --no-avif --no-jpg --no-png --webp-quality=75 --force

# Exits with an error when images fail, e.g. in a deployment script.
wp octopus run || exit 1
```

`wp octopus stats` first measures the images optimised before OIO counted the bytes saved, from the sizes of their files:
nothing is encoded again.

### Filters

| Filter                                | Default                                                                                                  | Sets                                                   | 2nd argument                                  |
|---------------------------------------|----------------------------------------------------------------------------------------------------------|--------------------------------------------------------|-----------------------------------------------|
| `octopus_backup_dir`                  | `uploads/octopus/originals-{key}`                                                                        | The folder of the original images.                     |                                               |
| `octopus_nice`                        | `19` in background jobs, `0` otherwise                                                                   | The priority of the libraries, `0` (normal) to `19`.   | Context: `upload`, `background` or `cli`      |
| `octopus_threads`                     | `4` on upload, `2` in background, `all` in CLI                                                           | The threads of the libraries at once, all together.    | Context: `upload`, `background` or `cli`      |
| `octopus_timeout`                     | `120`                                                                                                    | Seconds a library may take on a file, then stopped.    |                                               |
| `octopus_job_seconds_between_batches` | `0`                                                                                                      | A pause after each image of a background job, seconds. |                                               |
| `octopus_job_default_time_limit`      | `20`                                                                                                     | The duration of a batch of a background job, seconds.  |                                               |
| `octopus_binary_path`                 | Found in the `PATH`, then `/usr/bin`, `/usr/local/bin`, `/bin`, `/opt/homebrew/bin` and `/opt/local/bin` | The path of a library, or `false` to turn it off.      | Name of the library, e.g. `avifenc` or `nice` |

OIO looks the libraries up in the usual folders because PHP-FPM often passes an empty `PATH`. On multisite, the two
job filters carry the ID of the site: `octopus_2_job_default_time_limit` on the site 2.

```php
// In a mu-plugin: lighter background jobs on a small server.
add_filter('octopus_threads', function ($threads, $context) {
	return $context === 'background' ? 1 : $threads;
}, 10, 2);

// A library installed out of the usual folders.
add_filter('octopus_binary_path', function ($path, $name) {
	return $name === 'avifenc' ? '/home/me/bin/avifenc' : $path;
}, 10, 2);
```

### Contributing

To build a release zip, run the tests of OIO, update its translations or draw its logo, see
[CONTRIBUTING.md](CONTRIBUTING.md).

## ☕ Support

OIO is free, and will stay so. If it saves you time or bytes, you can buy me a coffee ☕:

- **PayPal**: [paypal.me/striffly](https://paypal.me/striffly/5EUR)
- **Bitcoin**: `bc1q3j7gxxykpu9zp3sgxyallw0hq0sr659zl35wre`

<img src="assets/images/bitcoin-qr.svg" alt="QR code of the Bitcoin address" width="160">

## 🙏 Credits

OIO is developed by [Clément Gayot](https://github.com/Striffly).

It started as a fork of [Squidge](https://github.com/ainsleyclark/squidge), created by
[Ainsley Clark](https://github.com/ainsleyclark) in 2021 with contributions from Roman K, Rohinish and karbivskij, and
was largely rewritten in 2026. Many thanks to them for the foundations: the idea of relying on the native libraries
of the server comes from Squidge.

Thanks also to [Media Optimizer](https://wordpress.org/plugins/yoohw-media-optimizer/) by YooHW for some ideas: the menu, the delivery page, the display of the bytes saved, and more.

## 📜 License

OIO is free software, released under the [GNU General Public License v3](LICENSE) or later.
