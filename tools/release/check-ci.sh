#!/usr/bin/env bash
#
# tools/release/check-ci.sh
#
# Stops a release whose commit is not checked. Run by release-it
# before anything else, it asks GitHub whether the CI workflow (the
# tests, Plugin Check and the Windows run) passed on HEAD, which must
# be pushed. Needs gh, logged in.

set -euo pipefail

cd "$(dirname "$0")/../.."

# gh picks the parent repository of a fork: name the one of origin.
repo=$(git remote get-url origin | sed -E 's#^(git@github\.com:|https://github\.com/)##; s#\.git$##')
head=$(git rev-parse HEAD)

git fetch --quiet origin
if [ "$head" != "$(git rev-parse '@{upstream}')" ]; then
	echo "HEAD is not the commit pushed: push it, wait for CI, then release." >&2
	exit 1
fi

result=$(gh run list -R "$repo" --commit "$head" --workflow ci.yml --limit 1 \
	--json status,conclusion -q '.[0] // empty | .status + " " + .conclusion')
case "$result" in
	'completed success')
		echo "CI passed on $head."
		;;
	'')
		echo "CI has not run on $head." >&2
		exit 1
		;;
	completed*)
		echo "CI: ${result#completed } on $head. Release once it passes." >&2
		exit 1
		;;
	*)
		echo "CI: still ${result%% *} on $head, wait for it." >&2
		exit 1
		;;
esac
