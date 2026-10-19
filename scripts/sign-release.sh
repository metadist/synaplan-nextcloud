#!/bin/sh
# Signs the tarball as `make sign` does (SHA-512, RSA) and writes the
# signature in one line of base64, as the App Store takes it.
#
#   scripts/sign-release.sh <private key file>
set -eu

cd "$(dirname "$0")/.."

app=synaplan_integration
openssl dgst -sha512 -sign "$1" "$app.tar.gz" | openssl base64 -A > "$app.tar.gz.sig"
echo "Signed $app.tar.gz."
