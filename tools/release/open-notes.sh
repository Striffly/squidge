#!/usr/bin/env bash
#
# tools/release/open-notes.sh
#
# Opens the notes of the next release. Run by release-it once the
# release is pushed: on dev, it adds an empty Unreleased title above
# the version just released in CHANGELOG.md, then commits and pushes
# it. A hotfix branch ends with its release: nothing to open.

set -euo pipefail

cd "$(dirname "$0")/../.."

if [ "$(git rev-parse --abbrev-ref HEAD)" != dev ]; then
	exit 0
fi

perl -0pi -e 's/^## /## Unreleased\n\n## /m' CHANGELOG.md

git commit --quiet \
	--message ':memo: Open the notes of the next release' \
	--message 'The empty Unreleased title of CHANGELOG.md waits for the notes of the next version.' \
	-- CHANGELOG.md
git push --quiet
