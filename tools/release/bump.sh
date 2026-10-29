#!/usr/bin/env bash
#
# tools/release/bump.sh <version>
#
# Writes the version of a release where release-it does not. Run by
# release-it once it has set package.json and the header of the plugin
# (@release-it/bumper), before its commit:
#
# - the Stable tag of readme.txt;
# - the Unreleased title of CHANGELOG.md, dated, then the changelog of
#   readme.txt from it (sync-changelog.sh);
# - the Project-Id-Version of the translations, and the .mo and
#   .l10n.php built from the .po. Needs wp-cli.

set -euo pipefail

cd "$(dirname "$0")/../.."

version=${1:?Usage: bump.sh <version>}

perl -pi -e "s/^Stable tag:.*/Stable tag: $version/" readme.txt

perl -pi -e "s/^## Unreleased\$/## $version – $(date +%F)/" CHANGELOG.md
bash tools/release/sync-changelog.sh

perl -pi -e "/project-id-version/i and s/(?<=Octopus Image Optimizer )\\d[\\w.+-]*/$version/" \
	languages/*.pot languages/*.po
wp i18n make-mo languages --quiet
wp i18n make-php languages --quiet
