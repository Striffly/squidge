#!/usr/bin/env bash
#
# tools/release/check-wordpress.sh
#
# Stops a release not tested with the latest major WordPress. Run by
# release-it before anything else, it checks that CI tests it (the
# WordPress of composer.lock, which the tests install), then that the
# Tested up to of readme.txt says so: WordPress.org warns about a
# plugin not tested with the latest versions. Needs curl and jq.

set -euo pipefail

cd "$(dirname "$0")/../.."

# Whether the version $1 is older than $2.
older() {
	[ "$1" != "$2" ] && [ "$(printf '%s\n%s\n' "$1" "$2" | sort -V | head -1)" = "$1" ]
}

latest=$(curl -fsS https://api.wordpress.org/core/version-check/1.7/ | jq -r '.offers[0].version' | cut -d. -f1,2)
locked=$(jq -r '.["packages-dev"][] | select(.name == "roots/wordpress-no-content") | .version' composer.lock | cut -d. -f1,2)
tested=$(sed -n 's/^Tested up to: *//p' readme.txt)

if older "$locked" "$latest"; then
	echo "CI tests WordPress $locked, but $latest is out: run" >&2
	echo "  composer update roots/wordpress-no-content wp-phpunit/wp-phpunit" >&2
	echo "then commit, push, wait for CI and set Tested up to: $latest in readme.txt." >&2
	exit 1
fi

if older "$tested" "$latest"; then
	echo "readme.txt: Tested up to: $tested, but CI tests WordPress $latest: update it." >&2
	exit 1
fi

echo "Tested up to WordPress $latest, as CI tests it."
