#!/usr/bin/env bash
#
# tools/check-translations.sh
#
# Stops a release whose translations lag behind the code. It
# runs `composer translations` and lets git tell whether the
# files of languages/ changed, then checks that every .po
# translates all its strings. Run by release-it before
# anything else, or with `bun run check-translations`. Needs
# wp-cli and gettext.

set -euo pipefail

cd "$(dirname "$0")/.."

if [ -n "$(git status --porcelain -- languages/)" ]; then
	echo "languages/ has uncommitted changes: commit them first." >&2
	exit 1
fi

composer translations --quiet

# The references (#: file:line) and the dates move without
# any string changing: a diff of only these is no change.
if ! git diff --quiet -I'^#: ' -I'^"(POT-Creation-Date|PO-Revision-Date): ' -- languages/ \
	|| [ -n "$(git ls-files --others --exclude-standard -- languages/)" ]; then
	echo "The translations were out of date: \`composer translations\` updated languages/." >&2
	echo "Translate what is missing, run it again, then commit." >&2
	exit 1
fi
git checkout --quiet -- languages/

failed=0
for po in languages/*.po; do
	stats=$(msgfmt --statistics -o /dev/null "$po" 2>&1)
	if [[ "$stats" =~ untranslated|fuzzy ]]; then
		echo "$po: $stats" >&2
		failed=1
	fi
done

if [ "$failed" -eq 1 ]; then
	echo "Translate what is missing, run \`composer translations\`, then commit." >&2
	exit 1
fi

echo "Translations up to date."
