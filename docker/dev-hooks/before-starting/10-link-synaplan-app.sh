#!/bin/sh
# Bind-mounting the app onto custom_apps/ before first install makes
# /var/www/html look non-empty, so the image skips copying Nextcloud and
# occ fails with "Cannot write into apps directory". Link the repo in
# after the html tree exists instead.
set -eu
mkdir -p /var/www/html/custom_apps
if [ -d /mnt/synaplan_integration/appinfo ]; then
  ln -sfn /mnt/synaplan_integration /var/www/html/custom_apps/synaplan_integration
fi
