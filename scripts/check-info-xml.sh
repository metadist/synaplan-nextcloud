#!/bin/sh
# Validates appinfo/info.xml against the App Store schema: the App Store
# refuses a release whose info.xml does not validate.
#
#   scripts/check-info-xml.sh
set -eu

cd "$(dirname "$0")/.."

xsd="$(mktemp)"
trap 'rm -f "$xsd"' EXIT
curl -sfL --retry 3 https://apps.nextcloud.com/schema/apps/info.xsd -o "$xsd"

# shellcheck disable=SC2016 # the PHP code expands its own variables
php -r '
libxml_use_internal_errors(true);
$info = new DOMDocument();
$info->load("appinfo/info.xml");
if ($info->schemaValidate($argv[1])) {
	echo "appinfo/info.xml validates against the App Store schema.\n";
	exit(0);
}
foreach (libxml_get_errors() as $error) {
	fwrite(STDERR, sprintf("::error file=appinfo/info.xml,line=%d::%s\n", $error->line, trim($error->message)));
}
exit(1);
' "$xsd"
