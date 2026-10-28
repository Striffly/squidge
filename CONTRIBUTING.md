# Contributing to OIO

Thanks for helping! Bugs and ideas go to the [issues](https://github.com/Striffly/octopus-image-optimizer/issues),
changes to a pull request.

## Conventions

Follow the conventions of [CLAUDE.md](CLAUDE.md), e.g. for the commits: in English, the subject starting with a
[gitmoji](https://gitmoji.dev/) shortcode, then a verb in the imperative.

## Release zip

Needed: [Composer](https://getcomposer.org/), [Bun](https://bun.sh/), [Node.js](https://nodejs.org/) 22 or later and
`zip`.

```bash
bun install
bun run release:zip # octopus-image-optimizer.zip
```

## Tests

The integration tests run on the test suite of WordPress: they upload images and check the files on the disk.

Needed:

- The [libraries](README.md#-requirements): a test needing one that is not installed is skipped.
- A MySQL or MariaDB database of their own.

> [!WARNING]
> The tests empty their database at each run: never give them the one of a site.

```bash
# PHPUnit, the WordPress test library and WordPress itself.
composer install

# The database, by default wordpress_test on 127.0.0.1 as root without password.
export WP_TESTS_DB_NAME=wordpress_test WP_TESTS_DB_USER=root \
	WP_TESTS_DB_PASSWORD=root WP_TESTS_DB_HOST=127.0.0.1:3306

composer test             # On a single site.
composer test:multisite   # On a network.
```

The GitHub workflow runs them on PHP 8.2 and 8.4, on a single site and on a network, at each push and before each
release.

## Translations

OIO is written in English and translated in French, in `languages/`. Once on WordPress.org, the translations of
[translate.wordpress.org](https://translate.wordpress.org/) come first.

Needed: [WP-CLI](https://wp-cli.org/) and gettext.

```bash
# The .pot, then the .po, .mo, .l10n.php and .json of each language.
composer translations
```

The pre-commit hook runs it, and adds the updated files of `languages/` to the commit. A release stops when a `.po`
lags behind the code.

## Logo and banner

The assets of the page of OIO on WordPress.org are in `.wordpress-org/`.

```bash
# The logo and the banners, drawn from the pixel grids of tools/brand/sprites/,
# in assets/images/ and .wordpress-org/.
bun run brand

# The screenshots, from captures of the admin at 1408x880 and 2x, in the order of readme.txt.
bun tools/brand/screenshots.js overview.png optimise.png delivery.png settings.png media.png
```
