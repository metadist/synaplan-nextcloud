#!/bin/sh
# Checks that the tarball from `make package` holds the app and nothing from
# development.
#
#   scripts/check-tarball.sh [synaplan_integration.tar.gz]
set -eu

app=synaplan_integration
tarball="${1:-$app.tar.gz}"
contents="$(tar -tzf "$tarball")"

for path in appinfo/info.xml appinfo/routes.php lib/AppInfo/Application.php js/ templates/ l10n/; do
	echo "$contents" | grep -Fxq "$app/$path" || {
		echo "::error::$tarball is missing $path." >&2
		exit 1
	}
done

if echo "$contents" | grep -qE "^$app/(tests|node_modules|vendor|src|\.local|\.github)/"; then
	echo "::error::$tarball contains development files." >&2
	exit 1
fi

echo "$tarball: the app and nothing from development."
