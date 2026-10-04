#!/usr/bin/env bash
# Builds the release zip: production autoloader only, everything in .distignore left out.
#
#   bash bin/build-zip.sh          → build/ranksphere.zip, updates itself from ranksphere.cloud
#   bash bin/build-zip.sh --wporg  → build/ranksphere-wporg.zip for WordPress.org: no Updates
#                                    directory, no "Update URI" header (WordPress.org updates it)
#
# Both unpack to ranksphere/ – the folder name WordPress updates in place.
set -euo pipefail

cd "$(dirname "$0")/.."
variant="${1:-}"
rm -rf build/ranksphere
mkdir -p build/ranksphere

rsync -a --exclude-from=.distignore --exclude=vendor ./ build/ranksphere/

if [ "$variant" = "--wporg" ]; then
	rm -rf build/ranksphere/src/Updates
	sed -i '/^ \* Update URI:/d' build/ranksphere/ranksphere.php
	zip_name="ranksphere-wporg.zip"
else
	zip_name="ranksphere.zip"
fi

cp composer.json composer.lock build/ranksphere/
composer install --working-dir=build/ranksphere --no-dev --prefer-dist --optimize-autoloader --no-interaction --quiet
rm build/ranksphere/composer.lock

rm -f "build/$zip_name"
(cd build && zip -qr "$zip_name" ranksphere)
echo "build/$zip_name"
