#!/bin/sh
# Runs once, right after the official image installed Nextcloud, as www-data
# and before Apache starts. Everything that needs HTTP (folders, shares) is in
# docker/dev-seed.sh, which `make dev-up` runs on every start.
set -eu

occ() { php /var/www/html/occ "$@"; }

# The before-starting hook links the app on every start, but only after this
# hook; link it now so the settings below land on a working app.
mkdir -p /var/www/html/custom_apps
ln -sfn /mnt/synaplan_integration /var/www/html/custom_apps/synaplan_integration

occ background:cron
occ config:system:set allow_local_remote_servers --value true --type boolean
occ config:system:set skeletondirectory --value ''
occ config:system:set default_language --value en
# Its welcome dialog covers the Files app on every first visit, also in E2E runs.
occ app:disable firstrunwizard

# Mail goes to the Synaplan stack's MailHog (http://localhost:8025).
occ config:system:set mail_smtpmode --value smtp
occ config:system:set mail_smtphost --value mailhog
occ config:system:set mail_smtpport --value 1025 --type integer
occ config:system:set mail_from_address --value nextcloud
occ config:system:set mail_domain --value example.org

# The app talks to Synaplan inside Docker; people's browsers use the Vite UI.
# The e2e stack (docker-compose.ci.yml) points both at the Synaplan test stack.
occ config:app:set synaplan_integration active_env --value local
occ config:app:set synaplan_integration synaplan_url_local --value "${SYNAPLAN_URL:-http://backend}"
occ config:app:set synaplan_integration synaplan_public_url_local --value "${SYNAPLAN_PUBLIC_URL:-http://localhost:5173}"

# The test people of the cloud journeys; ada and bob match the Keycloak users.
for person in ada:Ada:ada@example.org bob:Bob:bob@example.org carol:Carol:carol@example.org; do
  uid=${person%%:*}
  rest=${person#*:}
  OC_PASS="${uid}-dev-pass" occ user:add --password-from-env --display-name="${rest%%:*}" "$uid"
  occ user:setting "$uid" settings email "${rest#*:}"
done

if [ "${NC_OIDC:-1}" != 1 ]; then
  echo "NC_OIDC=0: organisation login (user_oidc) not set up"
  exit 0
fi

if ! occ app:install user_oidc; then
  echo "user_oidc could not be installed; organisation login stays off" >&2
  exit 0
fi

# Keep the password login next to the Keycloak button, let Keycloak's ada
# sign in as the local ada instead of a second account, and allow the plain
# http://localhost:8081 of this stack (user_oidc demands HTTPS otherwise).
occ config:app:set user_oidc allow_multiple_user_backends --value 1
occ config:app:set user_oidc allow_insecure_http --value 1 --type boolean --lazy
occ config:system:set user_oidc soft_auto_provision --type boolean --value true
occ user_oidc:provider keycloak \
  --clientid="${NC_OIDC_CLIENT_ID:-nextcloud}" \
  --clientsecret="${NC_OIDC_SECRET:-nextcloud-dev-secret}" \
  --discoveryuri="${NC_OIDC_DISCOVERY:-http://keycloak:8080/realms/synaplan/.well-known/openid-configuration}" \
  --scope="openid email profile" \
  --unique-uid=0 \
  --mapping-uid=preferred_username
