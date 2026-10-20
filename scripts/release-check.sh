#!/bin/sh
# Checks that a release tag, appinfo/info.xml, package.json and CHANGELOG.md
# agree, and prints the result for $GITHUB_OUTPUT.
#
#   scripts/release-check.sh v1.7.0        release: "## 1.7.0 – YYYY-MM-DD"
#   scripts/release-check.sh v1.7.0-rc.1   candidate for 1.7.0
#   scripts/release-check.sh               dry run: info.xml against itself
set -eu

cd "$(dirname "$0")/.."

fail() {
	echo "::error::$1" >&2
	exit 1
}

info_version="$(sed -n 's:.*<version>\(.*\)</version>.*:\1:p' appinfo/info.xml | head -n 1)"
package_version="$(sed -n 's/^  "version": "\(.*\)",$/\1/p' package.json | head -n 1)"
[ -n "$info_version" ] || fail "appinfo/info.xml has no <version>."

dry_run=false
tag="${1:-}"
if [ -z "$tag" ]; then
	dry_run=true
	tag="v$info_version"
fi

echo "$tag" | grep -Eq '^v[0-9]+\.[0-9]+\.[0-9]+(-rc\.[0-9]+)?$' \
	|| fail "Tag $tag is neither vX.Y.Z nor vX.Y.Z-rc.N."

version="${tag#v}"
version="${version%%-rc.*}"
prerelease=false
case "$tag" in
	*-rc.*) prerelease=true ;;
esac

[ "$info_version" = "$version" ] \
	|| fail "appinfo/info.xml says $info_version, the tag $tag needs $version."
[ "$package_version" = "$version" ] \
	|| fail "package.json says $package_version, the tag $tag needs $version."

escaped="$(echo "$version" | sed 's/\./\\./g')"
heading="$(grep -E "^## \[?$escaped\]?( |$)" CHANGELOG.md | head -n 1 || true)"
[ -n "$heading" ] || fail "CHANGELOG.md has no section for $version."
if [ "$dry_run" = false ] && [ "$prerelease" = false ]; then
	echo "$heading" | grep -Eq "^## \[?$escaped\]? (–|-) [0-9]{4}-[0-9]{2}-[0-9]{2}$" \
		|| fail "CHANGELOG.md gives $version no release date; write the heading as \"## $version – YYYY-MM-DD\"."
fi

echo "version=$version"
echo "prerelease=$prerelease"
echo "dry_run=$dry_run"
