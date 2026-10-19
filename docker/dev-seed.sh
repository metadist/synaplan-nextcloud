#!/bin/sh
# Runs on every `make dev-up`, as www-data inside the Nextcloud container once
# Apache answers. Safe to repeat: enables the app and gives Ada the folders and
# shares the cloud journeys use.
set -eu

occ() { php /var/www/html/occ "$@"; }

# appinfo/info.xml may stop below the running server version; a dev stack
# enables the app anyway.
occ app:enable synaplan_integration >/dev/null 2>&1 || occ app:enable --force synaplan_integration

ADA=ada:ada-dev-pass
DAV=http://localhost/remote.php/dav/files/ada
OCS=http://localhost/ocs/v2.php/apps/files_sharing/api/v1/shares

for folder in Specs Incoming-DE Incoming-EN Reports Invoices Exports Projects Projects/Q3 HR HR/Contracts; do
  code=$(curl -s -o /dev/null -w '%{http_code}' -u "$ADA" -X MKCOL "$DAV/$folder")
  case "$code" in
    201 | 405) ;;
    *) echo "Creating $folder for ada answered HTTP $code" >&2; exit 1 ;;
  esac
done

share() { # path, user, permissions
  if curl -s -u "$ADA" -H 'OCS-APIRequest: true' "$OCS?path=/$1&format=json" | grep -q "\"share_with\":\"$2\""; then
    return
  fi
  curl -s -o /dev/null -u "$ADA" -H 'OCS-APIRequest: true' -X POST "$OCS" \
    -d "path=/$1" -d shareType=0 -d "shareWith=$2" -d "permissions=$3"
}

share Incoming-DE bob 15 # read, update, create, delete
share Specs bob 1        # read

echo "Seeded: ada's folders; Incoming-DE (edit) and Specs (read) shared with bob"
