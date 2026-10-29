#!/usr/bin/env bash
#
# tools/release/check-notes.sh
#
# Stops a release without notes. Run by release-it before anything
# else, it checks that CHANGELOG.md has notes under Unreleased:
# readme.txt gets them at the release (see sync-changelog.sh).

set -euo pipefail

cd "$(dirname "$0")/../.."

# The lines between the Unreleased title and the next version.
notes=$(awk '
	$0 == "## Unreleased" { open = 1; next }
	open && /^## / { exit }
	open && NF { print }
' CHANGELOG.md)

if [ -z "$notes" ]; then
	echo "CHANGELOG.md: no notes under ## Unreleased." >&2
	exit 1
fi

echo "Notes written under ## Unreleased."
