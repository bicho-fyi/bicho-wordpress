#!/usr/bin/env bash
set -eu
cd "$(dirname "$0")/.."
npm ci
composer install --no-dev --no-interaction --prefer-dist
npm run build
