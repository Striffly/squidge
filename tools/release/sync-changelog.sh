#!/usr/bin/env bash
#
# tools/release/sync-changelog.sh
#
# Writes the changelog of readme.txt from CHANGELOG.md, the only one
# written by hand. Run by release-it once the version is set: each
# version of OIO (1.0.0 and later, not the Unreleased notes) becomes a
# = x.y.z = title, its notes one * line each, as WordPress.org reads
# them. The versions of Squidge stay in CHANGELOG.md only.

set -euo pipefail

cd "$(dirname "$0")/../.."

perl -0777 -i -e '
	open my $changelog, "<", "CHANGELOG.md" or die "CHANGELOG.md: $!\n";
	my $notes = do { local $/; <$changelog> };
	my $versions = "";
	while ($notes =~ /^## (\S+)[^\n]*\n\n(.*?)(?=^## |\z)/msg) {
		my ($version, $body) = ($1, $2);
		next if $version eq "Unreleased";
		last if $version =~ /^0\./;
		$body =~ s/\n +/ /g;
		$body =~ s/^- /* /mg;
		$body =~ s/\s+\z//;
		$versions .= "= $version =\n$body\n\n";
	}
	my $readme = <>;
	$readme =~ s/(^== Changelog ==\n\n).*?(^The versions of Squidge)/$1$versions$2/ms
		or die "readme.txt: no == Changelog == to write.\n";
	print $readme;
' readme.txt
