<p align="left">
    <img alt="logo" src="https://github.com/ainsleyclark/squidge/blob/master/assets/icon.svg" width="40%">
</p>

# Squidge

Is a **FREE** WordPress plugin, built with developers in mind, for the compression and conversion of images using `jpegoptim`,
`optipng`, `cwebp`, and `libavif`. It’s **extremely** simple to use and is designed to increase PSI and boost rankings.

[![Maintenance](https://img.shields.io/badge/Maintained%3F-yes-green.svg)](https://GitHub.com/Naereen/StrapDown.js/graphs/commit-activity)
[![GNU General Public License 3.0](https://img.shields.io/github/license/ainsleyclark/squidge.svg)](https://www.gnu.org/licenses/gpl-3.0.en.html)
[![Donate](https://img.shields.io/badge/Donate-PayPal-green.svg)](https://www.paypal.com/donate?hosted_button_id=PQG5A34KWUXRJ)
[![Twitter](https://img.shields.io/twitter/follow/ainsleydev)](https://twitter.com/ainsleydev)


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

Compresses JPG images in place using `jpegoptim`.

Compresses PNG images in place using `optipng`.

Converts JPG and PNG images to `.webp` files using `cwebp` with the appended extension  e.g. `image.jpg.webp`.

Converts JPG and PNG images to `.avif` files using `libavif` with the appended extension  e.g. `image.jpg.avif`.

## Installation

1. Go to the [releases](https://github.com/ainsleyclark/squidge/releases) section and download the plugin.
2. Upload the `squidge` plugin to your `/wp-content/plugins/` directory.
3. Activate the plugin through the "Plugins" menu in WordPress.
4. Check the Settings tab under `Settings | Squidge Options` to ensure the libraries are installed, if they aren't,
   run the commands listed dependent on your operating system.
5. Check the individual optimisation tabs and adjust settings accordingly.
6. Done!

To install the plugin from this repository instead of a release, run `composer install` in its folder: it installs
the dependencies in `vendor-prefixed/`, with their classes prefixed by [Strauss](https://github.com/BrianHenryIE/strauss)
so that they cannot collide with the copies other plugins load.

## Render Images

The compressed JPG and PNG files replace the originals, so your whole site uses them. There are two ways to also
offer the AVIF and WebP versions.

**Automatically**: on the `Overview` tab of the settings, tick `Use modern formats (WebP, AVIF)`. Images of the post
content and images rendered by `wp_get_attachment_image()` (featured images, galleries, most themes) are then wrapped
in a `<picture>` element with AVIF and WebP sources. This option is off by default.

**In templates**: the `squidge_image` helper function always returns a `<picture>` element with the AVIF and WebP
versions that exist on the file system, whatever this option.

### Function
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

### Output
```html
<picture class="squidge-picture hero">
	<source type="image/avif" srcset="/sample-image.jpg.avif 2500w, /sample-image-300x200.jpg.avif 300w" sizes="(max-width: 2500px) 100vw, 2500px">
	<source type="image/webp" srcset="/sample-image.jpg.webp 2500w, /sample-image-300x200.jpg.webp 300w" sizes="(max-width: 2500px) 100vw, 2500px">
	<img data-squidge src="/sample-image.jpg" srcset="/sample-image.jpg 2500w, /sample-image-300x200.jpg 300w" sizes="(max-width: 2500px) 100vw, 2500px" width="2500" height="1667" alt="Alt text">
</picture>
```

## Screenshots

### Plugin settings page

![Squidge Settings Page](https://github.com/ainsleyclark/squidge/blob/master/assets/screenshot-1.png)

### Example compression tab

![Squidge Example Compression Tab](https://github.com/ainsleyclark/squidge/blob/master/assets/screenshot-2.png)

## FAQs

### Does the plugin replace existing images?

Yes, the JPG and PNG files of the uploads folder are replaced with their compressed version, so your whole site
uses them. The originals are kept in `uploads/squidge-originals/` and every new optimisation starts again from them.

### What happens when I delete Squidge?

Deleting Squidge removes its settings and data from the database, and keeps the files: the compressed images, their
WebP and AVIF versions and the originals in `uploads/squidge-originals/`. To get your original images back and remove
every Squidge file, run `Restore original images` on the settings page (or `wp squidge restore`) before deleting it.
Squidge reminds it when it is deactivated.

### What are the supported operating systems?

Windows, Linux, & Mac OSX.

### How are JPGs optimized?

```bash
# input-file.jpg is first restored from its original in uploads/squidge-originals/
jpegoptim --strip-all --overwrite --max={{ quality }} input-file.jpg
```

### How are PNGs optimized?

```bash
optipng -clobber -strip all -o {{ optimization }} -out input-file.png squidge-originals/input-file.png
````

### How are images converted to webp files?

```bash
cwebp -q {{ quality }} input-file.jpg -o input-file.jpg.webp
```

### How are images converted to avif files?

```bash
avifenc -q {{ quality }} --speed 6 input-file.jpg input-file.jpg.avif
```

With libavif older than 1.0, `-q` does not exist and the quality is passed as the equivalent `cq-level`:

```bash
avifenc --min 0 --max 63 -a end-usage=q -a cq-level={{ (100 - quality) * 63 / 100 }} --speed 6 -a tune=ssim input-file.jpg input-file.jpg.avif
```

## CLI

Squidge includes a built in `WP CLI` to help convert and compress images, you can see the commands below.

```bash
wp squidge
```

```bash
usage: wp squidge health
   or: wp squidge run
   or: wp squidge version
```

### Version

Outputs the current version number of Squidge.

```bash
wp squidge version
```

### Health

Checks the correct libraries are installed.

```bash
wp squidge health
```

### Run

Compresses and converts all images. This command will obtain images from the WP media library, compress them if they are
JPG/PNG's and convert them to the `.webp` and `.avif` file formats.

By default all optimisation methods are ran, but you can disable them using the arguments below.

**Simple example:**

```bash
wp squidge run
```

**With example arguments**

```bash
wp squidge run --jpeg=false --quality=80 --optimization=o3
```

#### Arguments

The formats and qualities default to the ones of the options page, the arguments override them.

| Argument        | Default Value | Accepted Values          | Description       |
|-----------------|---------------| ------------------------ | ----------------- |
| jpg             | options page  | `true`/`false`           | If JPG compression should run.
| png             | options page  | `true`/`false`           | If PNG compression should run.
| webp            | options page  | `true`/`false`           | If WebP conversion should run.
| avif            | options page  | `true`/`false`           | If AVIF conversion should run.
| quality         | options page  | `0` to `100`             | Quality of JPG compression and WebP conversion.
| optimization    | options page  | `o0` to `o7`             | Optimisation of PNG images.
| avif-quality    | options page  | `0` to `100`             | Quality of AVIF images.
| avif-speed      | `6`           | `0` to `10`              | Speed of the AVIF encoder. 0 to 6 make files of about the same size (0 is much slower), faster speeds make larger files.
| force           | `false`       | `true`/`false`           | Force reoptimization of already optimized images.
| regenerate      | `false`       | `true`/`false`           | Make the thumbnails again from the original images first (implies force), e.g. after the theme changed its image sizes.
| thumbnails-only | `false`       | `true`/`false`           | Optimize only thumbnails.

### Clean

Removes the WebP and AVIF files of all images. The JPG and PNG files stay compressed.

```bash
wp squidge clean
```

### Restore

Restores the original images and removes every file made by Squidge (WebP, AVIF, backups). Do it before deleting
Squidge to get your original images back. Images optimised by a version of Squidge older than 0.1.5 have no original
kept, they stay compressed.

```bash
wp squidge restore
```

## Road Map

- Add `SVGO` to optimise SVG images.
- More arguments passed to each service.
- Update meta data when images are converted or compressed.
- Output compression info in the Media Library.
- Create unit tests.
