#!/bin/sh
# Prints the CHANGELOG.md section of one version, for the GitHub release.
#
#   scripts/release-notes.sh 1.7.0
set -eu

cd "$(dirname "$0")/.."

awk -v version="$1" '
	/^## / {
		if (found) exit
		if ($2 == version || $2 == "[" version "]") { found = 1; next }
	}
	found { print }
' CHANGELOG.md
