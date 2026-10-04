#!/usr/bin/env bash
# Builds the release zip exactly like WordPress.org will see it: production autoloader only,
# everything in .distignore left out. Output: build/ranksphere/ and build/ranksphere.zip
set -euo pipefail

cd "$(dirname "$0")/.."
rm -rf build
mkdir -p build/ranksphere

rsync -a --exclude-from=.distignore --exclude=vendor ./ build/ranksphere/
cp composer.json composer.lock build/ranksphere/
composer install --working-dir=build/ranksphere --no-dev --prefer-dist --optimize-autoloader --no-interaction --quiet
rm build/ranksphere/composer.lock

(cd build && zip -qr ranksphere.zip ranksphere)
echo "build/ranksphere.zip"
