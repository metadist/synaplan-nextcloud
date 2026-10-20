#!/bin/sh
# Verifies the tarball's signature against a certificate: in a release the
# one the App Store holds for the app, which is what installs check.
#
#   scripts/verify-release.sh <certificate file>
set -eu

cd "$(dirname "$0")/.."

app=synaplan_integration
work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT

openssl base64 -d -A -in "$app.tar.gz.sig" -out "$work/signature.bin"
openssl x509 -in "$1" -pubkey -noout > "$work/public.pem"
openssl dgst -sha512 -verify "$work/public.pem" -signature "$work/signature.bin" "$app.tar.gz"
