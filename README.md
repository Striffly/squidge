<p align="left">
    <img alt="OIO – Octopus Image Optimizer" src="assets/icon-256x256.png" width="200">
</p>

# OIO – Octopus Image Optimizer

🐙 **Squeezes images. Spills no ink, sends no bill.**

OIO optimises the images of your WordPress media library with the libraries of your own server: `jpegoptim`,
`optipng` or `oxipng`, `pngquant`, `cwebp` and `avifenc`, or GD and Imagick for the WebP and AVIF formats. Free and
unlimited, no API key, no quota: your images never leave your server.

[![GNU General Public License 3.0](https://img.shields.io/github/license/Striffly/octopus-image-optimizer.svg)](https://www.gnu.org/licenses/gpl-3.0.en.html)

## Features

**Optimisation**

- Compresses JPG and PNG images in place, and creates their WebP and AVIF versions next to them (`photo.jpg.webp`,
  `photo.jpg.avif`), kept only when lighter than the compressed image.
- Keeps the original images: every optimisation starts again from them, and they can be restored at any time.
- Strips the metadata (camera, date, GPS position) from every optimised file.
- Handles CMYK JPGs, which the WebP and AVIF encoders cannot read.

**Media library**

- Optimises the images on upload, scaled down to a maximum size (2560 pixels by default, as WordPress does).
- Optimises, re-optimises or cleans up the whole library in the background, with a progress bar, pause and cancel.
  The job goes on when the page is closed.
- Spots the images optimised with older settings, or before the theme changed its image sizes, and makes their
  thumbnails again from the originals, scaled to the current maximum size.
- Shows the status of each image in the media library, and its errors in plain words.
- Shows the bytes saved, on each image of the media library and on the whole library, with what serving WebP and
  AVIF would save, and the disk used by the originals and the WebP and AVIF files.

**On the site**

- Serves AVIF and WebP, with the JPG or PNG as fallback (optional): in a `<picture>` element, or from the server
  with rewrite rules (written for Apache, given for nginx), which leaves the HTML as it is.
- Tests the delivery: types sent by the server, rewrite, originals kept private.
- `oio_image()` returns a `<picture>` in your own templates.

## Requirements

- WordPress 6.0 and PHP 8.2 or later.
- The JPG and PNG compression needs `jpegoptim` and `optipng` or `oxipng` installed on the server, and PHP allowed to
  run them (`proc_open()` or `exec()`). Linux and macOS have them; Windows has no official build of `jpegoptim` and
  `optipng`.
- The WebP and AVIF formats work without their libraries, with GD or Imagick when they support them (AVIF since
  WordPress 6.5). `cwebp` and `avifenc` are better: they run apart from PHP, `cwebp` makes the PNG graphics much
  lighter and `avifenc` keeps their colours sharper.

The settings page shows the missing libraries and how to install them, e.g. on Debian or Ubuntu:

```bash
sudo apt install jpegoptim optipng webp libavif-bin
```

On Windows, put the official builds of [`cwebp`](https://developers.google.com/speed/webp/download) and
[`avifenc`](https://github.com/AOMediaCodec/libavif/releases) on the `PATH`, or give their path with the
`oio_binary_path` filter: the WebP and AVIF formats are tested on Windows by a GitHub Action, the JPG and PNG are not
compressed there.

Each format works on its own: a missing library only turns its format off, or hands the WebP or AVIF to GD or
Imagick. `oxipng`, faster than `optipng`, replaces it
when installed (`apt install oxipng` on recent systems, or from its
[releases](https://github.com/oxipng/oxipng/releases)), and `pngquant` (`apt install pngquant`) adds an optional lossy
compression of the PNG images.

## Installation

1. Download the zip of the latest [release](https://github.com/Striffly/octopus-image-optimizer/releases).
2. Upload it in `Plugins > Add New > Upload Plugin`, and activate it.
3. Open `Settings > OIO`: the Overview tab checks the libraries.
4. Click `Optimise new images` to optimise the images already in the library.

To install it from this repository instead, run `composer install` in the plugin folder: it builds the dependencies
in `vendor-prefixed/`. To build the zip of a release, run `npm ci` then `npm run release:zip` (Composer, Node.js 22
or later and `zip` needed): it makes `octopus-image-optimizer.zip`.

## FAQ

### Does OIO replace my images?

Yes, the JPG and PNG files are replaced by their compressed version, so the whole site uses them right away, and
their originals are kept in `uploads/oio-originals/`. `Restore original images` on the settings page, or
`Restore original` on an image of the media library, puts them back.

### Does it serve WebP and AVIF automatically?

Once turned on, on the Delivery tab, in one of two ways:

- **In the HTML**: the images of the content and the ones output by `wp_get_attachment_image()` (featured images,
  galleries, most themes) are wrapped in a `<picture>` element, with the JPG or PNG as fallback. Works everywhere,
  but may break a layout styling `img` directly. In a custom theme, use [`oio_image()`](#theme-development).
- **From the server**: the server answers `photo.jpg` with `photo.jpg.avif` or `photo.jpg.webp` when the browser
  reads them. The HTML does not change and every image is served, CSS backgrounds included. OIO writes the rules in
  `uploads/.htaccess` on Apache and LiteSpeed; on nginx, add these ones to the configuration of the site:

```nginx
# In the http block:
map $http_accept $oio_suffix {
	default "";
	"~image/avif" ".avif";
	"~image/webp" ".webp";
}

# In the server block:
location ~* ^/wp-content/uploads/.+\.(jpe?g|png)$ {
	add_header Vary Accept;
	try_files $uri$oio_suffix $uri =404;
}
```

A CDN must vary its cache on the `Accept` header, or serve the JPG to everyone. `Test the delivery`, on the same
tab, checks what the server sends.

### What happens when the theme changes its image sizes?

The images optimised before are listed as "Older settings". `Re-optimise outdated images` makes their thumbnails again
from the originals, and optimises them.

### Does it slow the site down?

The background jobs run the libraries with the lowest priority: the requests of the site go first. On a small server,
the [filters](#filters) slow the jobs down further.

### Does it work on shared hosting?

Yes for the WebP and AVIF formats: without their libraries, or where PHP cannot run programs, GD or Imagick make
them. The JPG and PNG compression needs the libraries installed and PHP allowed to run them: most shared hosts do not
let you install them, ask your host. The Overview tab tells what is missing.

### What happens when I delete OIO?

Its settings and data are removed from the database, and the files stay: the compressed images, their WebP and AVIF
versions and the originals. To get your original images back, run `Restore original images` before deleting OIO,
which reminds it when it is deactivated.

### Which settings do the libraries use?

Every optimisation starts from the original image:

```bash
jpegoptim --strip-all --all-progressive --overwrite --max={quality} photo.jpg
optipng -clobber -strip all -o {level} -out photo.png oio-originals/photo.png
oxipng -o {level} --strip safe --force --out photo.png oio-originals/photo.png
pngquant --quality={quality - 15}-{quality} --strip --skip-if-larger --force --output tmp.png -- oio-originals/photo.png
cwebp -q {quality} -mt photo.jpg -o photo.jpg.webp
avifenc -q {quality} --speed 6 --ignore-exif --ignore-xmp photo.jpg photo.jpg.avif
```

`avifenc` older than 1.0 gets the quality as `cq-level` instead of `-q`. `oxipng` runs instead of `optipng` when
installed, after `pngquant` when the lossy compression is on (it then compresses `tmp.png`). In background jobs, `cwebp`
runs without `-mt`, `avifenc` with `--jobs 2` and `oxipng` with `--threads 2`. A PNG is also encoded with `cwebp -near_lossless 60`, kept if lighter: graphics
and screenshots get much lighter and sharper, photos are left lossy.

Without `cwebp` or `avifenc`, the WordPress image editor (GD or Imagick) makes the WebP at the same quality, and the
AVIF at the quality + 5 (up to 89, from 90 as is), for files close to the ones of `avifenc`. The PNG images are not
tried in near-lossless.

## Privacy

OIO sends nothing out of your server: no API, no tracking, no external request. The delivery test only requests the
site itself.

The optimised images lose their metadata, but the originals keep them, GPS position included. OIO writes a
`.htaccess` denying the web access to `uploads/oio-originals/`, which works with Apache and LiteSpeed. With nginx, add
this rule to the `server` block of the site:

```nginx
location ^~ /wp-content/uploads/oio-originals/ {
	deny all;
}
```

Or keep the originals out of the web root with the `oio_backup_dir` filter, before optimising any image. OIO does not
move the originals already kept: move the folder yourself when you change it.

```php
// e.g. next to the web root of a Bedrock site.
add_filter('oio_backup_dir', function () {
	return dirname(ABSPATH, 2) . '/oio-originals';
});
```

## Developers

### Theme development

The `<picture>` option of the Delivery tab serves the WebP and AVIF versions for the images of the content and the
ones output by `wp_get_attachment_image()`. The images a theme outputs with its own markup, e.g. from an ACF field,
are left as they are: serve them from the server, or use `oio_image()`, which returns their `<picture>` element
whatever the options.

```php
if (function_exists('oio_image')) {
	echo oio_image($image_id, 'hero');       // Class of the <picture> element.
	echo oio_image($image_id, 'hero', true); // data-src and data-srcset, for a JavaScript lazy loader.
}
```

```html
<picture class="oio-picture hero">
	<source type="image/avif" srcset="photo.jpg.avif 2500w, photo-300x200.jpg.avif 300w" sizes="(max-width: 2500px) 100vw, 2500px">
	<source type="image/webp" srcset="photo.jpg.webp 2500w, photo-300x200.jpg.webp 300w" sizes="(max-width: 2500px) 100vw, 2500px">
	<img data-oio src="photo.jpg" srcset="photo.jpg 2500w, photo-300x200.jpg 300w" sizes="(max-width: 2500px) 100vw, 2500px" width="2500" height="1667" alt="">
</picture>
```

Keep the `function_exists()` check: without it, the pages break while OIO is deactivated.

### WP-CLI

| Command          | What it does                                                                         |
|------------------|--------------------------------------------------------------------------------------|
| `wp oio health`  | Checks that the libraries are installed.                                             |
| `wp oio run`     | Optimises the whole media library, with the settings of the settings page.           |
| `wp oio clean`   | Removes the WebP and AVIF files. The JPG and PNG files stay compressed.              |
| `wp oio restore` | Restores the original images and removes every file made by OIO.                     |
| `wp oio stats`   | Prints the bytes saved on the media library. `--rebuild` measures every image again. |
| `wp oio version` | Shows the version of OIO.                                                            |

`wp oio run` takes these options:

- `--force` optimises the images again, `--regenerate` first makes their thumbnails again from the originals,
  `--thumbnails-only` leaves the full size images out.
- `--jpg=false`, `--png=false`, `--webp=false`, `--avif=false` turn a format off.
- `--quality=<0-100>` (JPG and WebP), `--avif-quality=<0-100>`, `--avif-speed=<0-10>` and `--optimization=o<0-7>`
  (PNG) override the settings.

`wp oio stats` first measures the images optimised before OIO counted the bytes saved, from the sizes of their files:
nothing is encoded again.

### Filters

| Filter                            | Default                                       | Sets                                                     |
|-----------------------------------|-----------------------------------------------|----------------------------------------------------------|
| `oio_backup_dir`                  | `uploads/oio-originals`                       | The folder of the original images.                       |
| `oio_nice`                        | `19` in background jobs, `0` otherwise        | The priority of the libraries, `0` (normal) to `19`.     |
| `oio_avif_jobs`                   | `4` on upload, `2` in background, `all` in CLI | The threads of `avifenc`.                                |
| `oio_webp_mt`                     | `false` in background jobs, `true` otherwise  | A second thread for `cwebp` (`-mt`).                     |
| `oio_oxipng_threads`              | `4` on upload, `2` in background, `all` in CLI | The threads of `oxipng`.                                 |
| `oio_job_seconds_between_batches` | `0`                                           | A pause after each image of a background job, seconds.   |
| `oio_job_default_time_limit`      | `20`                                          | The duration of a batch of a background job, seconds.    |
| `oio_binary_path`                 | Found in the `PATH`, then `/usr/bin`...       | The path of a library, or `false` to turn it off.        |

`oio_nice`, `oio_avif_jobs`, `oio_webp_mt` and `oio_oxipng_threads` receive the context as second argument: `upload`,
`background` or `cli`. On multisite, the two job filters carry the ID of the site: `oio_2_job_default_time_limit` on
the site 2.

`oio_binary_path` receives the name of the library as second argument (`jpegoptim`, `optipng`, `cwebp`, `avifenc`,
or `nice`); without `cwebp` or `avifenc`, GD or Imagick make the format. OIO looks the libraries up in the folders of
the `PATH`, then in `/usr/bin`, `/usr/local/bin`, `/bin`, `/opt/homebrew/bin` and `/opt/local/bin`: PHP-FPM often
passes an empty `PATH`.

```php
// In a mu-plugin: lighter background jobs on a small server.
add_filter('oio_avif_jobs', function ($jobs, $context) {
	return $context === 'background' ? 1 : $jobs;
}, 10, 2);

// A library installed out of the usual folders.
add_filter('oio_binary_path', function ($path, $name) {
	return $name === 'avifenc' ? '/home/me/bin/avifenc' : $path;
}, 10, 2);
```

### Tests

The integration tests run on the test suite of WordPress, with the libraries installed: they upload images and check
the files on the disk. `composer install` installs PHPUnit, the WordPress test library and WordPress itself. They need
a MySQL or MariaDB database of their own, emptied at each run: never the one of a site.

```bash
composer install
# The database, by default wordpress_test on 127.0.0.1 as root without password.
export WP_TESTS_DB_NAME=wordpress_test WP_TESTS_DB_USER=root WP_TESTS_DB_PASSWORD=root WP_TESTS_DB_HOST=127.0.0.1:3306
composer test             # On a single site.
composer test:multisite   # On a network.
```

A test needing a library that is not installed is skipped. The GitHub workflow runs them on PHP 8.2 and 8.4, on a
single site and on a network, at each push and before each release.

## Credits

OIO is developed by [Clément Gayot](https://github.com/Striffly).

It started as a fork of [Squidge](https://github.com/ainsleyclark/squidge), created by
[Ainsley Clark](https://github.com/ainsleyclark) in 2021 with contributions from Roman K, Rohinish and karbivskij, and
was largely rewritten in 2026. Many thanks to them for the foundations: the idea of relying on the native libraries
of the server comes from Squidge.

## License

OIO is free software, released under the [GNU General Public License v3](LICENSE) or later.
