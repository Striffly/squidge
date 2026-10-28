# Changelog

The versions of OIO – Octopus Image Optimizer, newest first. Before 1.0.0, it was
[Squidge](https://github.com/ainsleyclark/squidge).

## 1.0.0

- Renamed to OIO – Octopus Image Optimizer, starting again from Squidge 0.1.4: no migration of its data.
- The original images are kept in `uploads/octopus/originals-{key}/`, a folder whose URL cannot be guessed, and every
  optimisation starts again from them.
- WebP and AVIF files keep the extension of their source (`image.jpg.webp`), in `uploads/octopus/formats/`, and can
  be served on the site with `<picture>` or rewrite rules.
- Background optimisation of the media library, with pause and cancel, and re-optimisation when the settings or the
  thumbnail sizes change.
- Clean up: remove the WebP and AVIF files, or restore the original images.
- Status column in the media library and readable error notices.
- CMYK JPG images, AVIF quality settings, CPU limits adjustable by filters.
- Requires WordPress 6.2 and PHP 8.2.

## 0.1.4 – 2021-12-21

- Squidge: updated how the compressed images are marked in the post meta.

## 0.1.3 – 2021-12-21

- Squidge: marks the compressed images in the post meta, deployment to WordPress.org.

## 0.1.2 – 2021-12-05

- Squidge: escaped shell arguments, only the supported libraries can be run.

## 0.1.1 – 2021-11-27

- Squidge: settings link on the plugins page, readme fixes.

## 0.1.0 – 2021-11-25

- Squidge, initial release.
