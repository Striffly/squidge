# CLAUDE.md

## Commits

- In English, the subject starting with a [gitmoji](https://gitmoji.dev/) shortcode, then a verb in the imperative:
  `:sparkles: Roll the counts during a job`.
- A description under the subject, after a blank line: what changes and why, in a few sentences.

## Changes

- Work on `dev`: `main` only moves with a release (see [CONTRIBUTING.md](CONTRIBUTING.md)).
- With each change a user would notice, add a note under `## Unreleased` in `CHANGELOG.md`: the release copies the
  notes to `readme.txt`. Update `README.md`, and `readme.txt` where it says the same, when what they describe
  changes.

## Docs to keep in step

In the same commit as the change:

- The conventions (commits, branches, notes): this file and the Conventions and Branches of `CONTRIBUTING.md`.
- The release (`.release-it.json`, `tools/release/`, `release.yml`): the Release of `CONTRIBUTING.md`.
- The CI (`.github/workflows/`), the tests or their setup: the Tests of `CONTRIBUTING.md`.
- The translations (`composer translations`, `tools/check-translations.sh`): the Translations of `CONTRIBUTING.md`.
- The logo, the banners or the screenshots tools (`tools/brand/`): the Logo and banner of `CONTRIBUTING.md`.
- A feature, a setting or a default: the Features and the FAQ of `README.md`, and the Description and FAQ of
  `readme.txt`.
- The libraries, the requirements or the installation: the Requirements and Installation of `README.md`, and the
  Installation of `readme.txt`.
- A filter, a WP-CLI command or option, or a theme function: the Developers of `README.md`.
- What OIO sends, keeps or protects: the Privacy of `README.md` and `readme.txt`.
