#!/usr/bin/env bash
#
# tools/release/check-notes.sh
#
# Stops a release that is not ready to read. Run by release-it before
# anything else, it checks that CHANGELOG.md and readme.txt have notes
# under Unreleased, and that the Tested up to of readme.txt is the
# latest major WordPress, which CI tests (WordPress.org warns about a
# plugin not tested with the latest versions). Needs curl and jq.

set -euo pipefail

cd "$(dirname "$0")/../.."

failed=0

# The lines between the Unreleased title and the next title.
notes() {
	awk -v title="$2" -v next_title="$3" '
		$0 == title { open = 1; next }
		open && $0 ~ next_title { exit }
		open && NF { print }
	' "$1"
}

if [ -z "$(notes CHANGELOG.md '## Unreleased' '^## ')" ]; then
	echo "CHANGELOG.md: no notes under ## Unreleased." >&2
	failed=1
fi
if [ -z "$(notes readme.txt '= Unreleased =' '^=+ ')" ]; then
	echo "readme.txt: no notes under = Unreleased =." >&2
	failed=1
fi

latest=$(curl -fsS https://api.wordpress.org/core/version-check/1.7/ | jq -r '.offers[0].version' | cut -d. -f1,2)
tested=$(sed -n 's/^Tested up to: *//p' readme.txt)
if [ "$(printf '%s\n%s\n' "$tested" "$latest" | sort -V | head -1)" != "$latest" ]; then
	echo "readme.txt: Tested up to: $tested, but WordPress $latest is out. Check CI tested it, then update it." >&2
	failed=1
fi

if [ "$failed" -eq 1 ]; then
	exit 1
fi

echo "Notes written, tested up to WordPress $latest."
