#!/usr/bin/env bash
#
# tools/check-translations.sh [--stage]
#
# Keeps the translations in step with the code. It runs
# `composer translations` and lets git tell whether the
# files of languages/ changed, then checks that every .po
# translates all its strings. Needs wp-cli and gettext.
#
# --stage: run by lefthook before each commit, it adds the
# updated files of languages/ to the commit.
#
# Without it: run by release-it before anything else, or
# with `bun run check-translations`, it stops a release
# whose translations lag behind the code.

set -euo pipefail

cd "$(dirname "$0")/.."

stage=0
if [ "${1:-}" = '--stage' ]; then
	stage=1
fi

if [ "$stage" -eq 0 ] && [ -n "$(git status --porcelain -- languages/)" ]; then
	echo "languages/ has uncommitted changes: commit them first." >&2
	exit 1
fi

composer translations --quiet

# The references (#: file:line) and the dates move without
# any string changing: a diff of only these is no change,
# and goes back to what git holds.
if git diff --quiet -I'^#: ' -I'^"(POT-Creation-Date|PO-Revision-Date): ' -- languages/ \
	&& [ -z "$(git ls-files --others --exclude-standard -- languages/)" ]; then
	git checkout --quiet -- languages/
elif [ "$stage" -eq 1 ]; then
	git add -- languages/
else
	echo "The translations were out of date: \`composer translations\` updated languages/." >&2
	echo "Translate what is missing, run it again, then commit." >&2
	exit 1
fi

failed=0
for po in languages/*.po; do
	stats=$(msgfmt --statistics -o /dev/null "$po" 2>&1)
	if [[ "$stats" =~ untranslated|fuzzy ]]; then
		echo "$po: $stats" >&2
		failed=1
	fi
done

if [ "$failed" -eq 1 ] && [ "$stage" -eq 1 ]; then
	echo "Translate what is missing, stage the .po, then commit again." >&2
	exit 1
elif [ "$failed" -eq 1 ]; then
	echo "Translate what is missing, run \`composer translations\`, then commit." >&2
	exit 1
fi

echo "Translations up to date."
