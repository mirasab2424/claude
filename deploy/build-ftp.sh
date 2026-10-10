#!/usr/bin/env bash
# Собирает папку для заливки по FTP поверх работающего сайта (используется в GitHub Actions).
# В сборке НЕТ .env, базы и storage — на сервере они остаются как есть.
#   deploy/build-ftp.sh [папка]   (по умолчанию build/ftp)
set -euo pipefail

ROOT=$(cd "$(dirname "$0")/.." && pwd)
WEB=$(realpath -m "${1:-$ROOT/build/ftp}")
CORE="$WEB/core"

rm -rf "$WEB"
mkdir -p "$CORE"
git -C "$ROOT" archive HEAD | tar -x -C "$CORE"
cd "$CORE"

composer install --no-dev --optimize-autoloader --no-interaction --prefer-dist --quiet
find vendor -name .git -type d -prune -exec rm -rf {} +
find vendor -mindepth 3 -maxdepth 3 -type d \( -name tests -o -name Tests -o -name test_files -o -name docs -o -name .github \) -prune -exec rm -rf {} +
composer dump-autoload --optimize --no-dev --quiet

cp .env.example .env
php artisan filament:assets --quiet
rm .env
rm -rf deploy tests .github phpunit.xml .editorconfig .styleci.yml CLAUDE.md storage

# По этой метке первый запрос после заливки применит миграции (App\Support\PostDeploy).
git -C "$ROOT" rev-parse HEAD > deploy-version

source "$ROOT/deploy/layout.sh"
cd "$ROOT"
prepare_webroot "$WEB"
rm -rf "$WEB/storage"

echo "Готово: $WEB ($(find "$WEB" -type f | wc -l) файлов)"
