#!/usr/bin/env bash
# Installs the pinned Composer dependencies (composer.lock) into vendor/ without dev packages, with an
# optimized, authoritative classmap autoloader.
set -euo pipefail
cd "$(dirname "$0")"
composer install --no-dev --no-interaction --no-progress --prefer-dist --optimize-autoloader --classmap-authoritative
