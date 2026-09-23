#!/usr/bin/env bash
# Build build/embed-forms-<version>.zip, installable from Plugins > Add New.
set -euo pipefail
cd "$(dirname "$0")/.."
version=$(sed -n 's/^ \* Version: *//p' embed-forms.php)
rm -rf build/embed-forms && mkdir -p build/embed-forms
cp -r embed-forms.php src assets README.md build/embed-forms/
[ -d languages ] && cp -r languages build/embed-forms/
(cd build && rm -f "embed-forms-$version.zip" && zip -qr "embed-forms-$version.zip" embed-forms)
echo "build/embed-forms-$version.zip"
