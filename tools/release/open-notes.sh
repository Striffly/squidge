#!/usr/bin/env bash
#
# tools/release/open-notes.sh
#
# Opens the notes of the next release. Run by release-it once the
# release is pushed: it adds an empty Unreleased title above the
# version just released, in CHANGELOG.md and readme.txt, then commits
# and pushes it. WordPress.org reads the readme.txt of the tag
# released, never this one.

set -euo pipefail

cd "$(dirname "$0")/../.."

perl -0pi -e 's/^## /## Unreleased\n\n## /m' CHANGELOG.md
perl -0pi -e 's/^(== Changelog ==\n\n)/$1= Unreleased =\n\n/m' readme.txt

git commit --quiet \
	--message ':memo: Open the notes of the next release' \
	--message $'The empty Unreleased titles of CHANGELOG.md and readme.txt wait\nfor the notes of the next version.' \
	-- CHANGELOG.md readme.txt
git push --quiet
