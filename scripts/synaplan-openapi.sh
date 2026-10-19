#!/usr/bin/env bash
# Dump the OpenAPI spec and route table of the Synaplan the app is tested
# against, for the contract test (tests/Contract). Neither file is committed.
#
#   scripts/synaplan-openapi.sh
#       the image pinned in .github/workflows/ci.yml (SYNAPLAN_IMAGE_DIGEST),
#       or SYNAPLAN_IMAGE when set
#   SYNAPLAN_SRC_CONTAINER=synaplan-backend scripts/synaplan-openapi.sh
#       a running Synaplan dev backend, to try changes before they are released
set -euo pipefail

repo="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
out="$repo/tests/Contract/.cache"

if [ -z "${SYNAPLAN_SRC_CONTAINER:-}" ] && [ -z "${SYNAPLAN_IMAGE:-}" ]; then
	digest="$(grep -oE 'SYNAPLAN_IMAGE_DIGEST:[[:space:]]+sha256:[a-f0-9]+' "$repo/.github/workflows/ci.yml" | awk '{print $2}')"
	if [ -z "$digest" ]; then
		echo "No SYNAPLAN_IMAGE_DIGEST in .github/workflows/ci.yml; set SYNAPLAN_IMAGE." >&2
		exit 1
	fi
	SYNAPLAN_IMAGE="ghcr.io/metadist/synaplan@$digest"
fi

console() {
	if [ -n "${SYNAPLAN_SRC_CONTAINER:-}" ]; then
		docker exec "$SYNAPLAN_SRC_CONTAINER" sh -c "cd /var/www/backend && php bin/console $1"
	else
		# Symfony's dotenv wants a .env file; the dumps read routes and attributes only.
		docker run --rm --entrypoint=/bin/sh "$SYNAPLAN_IMAGE" \
			-c "cd /var/www/backend && cp .env.test .env && APP_ENV=test php bin/console $1"
	fi
}

mkdir -p "$out"
echo "Synaplan: ${SYNAPLAN_SRC_CONTAINER:-$SYNAPLAN_IMAGE}"
console 'nelmio:apidoc:dump --format=json' > "$out/openapi.json"
console 'debug:router --format=json' > "$out/routes.json"

for file in openapi routes; do
	if ! head -c 1 "$out/$file.json" | grep -q '{'; then
		echo "The $file dump is not JSON:" >&2
		head -c 500 "$out/$file.json" >&2
		exit 1
	fi
done
echo "Wrote $out/openapi.json and routes.json"
