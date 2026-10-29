# Contributing to OIO

Thanks for helping! Bugs and ideas go to the [issues](https://github.com/Striffly/octopus-image-optimizer/issues),
changes to a pull request.

## Conventions

Follow the conventions of [CLAUDE.md](CLAUDE.md), e.g. for the commits: in English, the subject starting with a
[gitmoji](https://gitmoji.dev/) shortcode, then a verb in the imperative.

## Branches

- `dev`: the work in progress. Every change lands there.
- `main`: the last release, always on its `:bookmark: Release x.y.z` commit. Only release-it moves it, by
  fast-forward.
- `hotfix/x.y.z`: an urgent fix, from `main`, released the same way. Merge it into `dev` afterwards.

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

## Release

Needed: [Composer](https://getcomposer.org/), [Bun](https://bun.sh/), [Node.js](https://nodejs.org/) 22 or later and
`zip`.

Write the notes under `Unreleased` in `CHANGELOG.md` as the changes come: the release writes them in `readme.txt`.
When a new major WordPress is out, have CI test it with
`composer update roots/wordpress-no-content wp-phpunit/wp-phpunit`, then update `Tested up to:` in `readme.txt`. Then,
on `dev` or a hotfix branch, once CI passed on it:

```bash
bun run release:dry    # What it would do.
bun run release        # Patch, minor or major.
bun run release 1.2.0  # A given version, or 1.2.0-beta.1 for a pre-release.
```

release-it then:

1. checks CI, the notes, `Tested up to:` and the translations;
2. sets the version everywhere (never by hand), dates the notes and copies them to `readme.txt`;
3. commits `:bookmark: Release x.y.z`, tags it and pushes;
4. fast-forwards `main` to the release;
5. on `dev`, opens an empty `Unreleased` for the next one.

The tag runs CI again, then publishes the zip on GitHub and WordPress.org.

To build the zip only: `bun install && bun run release:zip`.
