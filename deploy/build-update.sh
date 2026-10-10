#!/usr/bin/env bash
# Архив-обновление для shared-хостинга: код и статика БЕЗ vendor, базы, .env и загруженных файлов.
# Распаковывается в htdocs поверх старых файлов; данные и пароли не трогаются.
#   deploy/build-update.sh [файл.zip]   (по умолчанию build/update.zip)
set -euo pipefail
ROOT=$(cd "$(dirname "$0")/.." && pwd)
OUT=$(realpath -m "${1:-$ROOT/build/update.zip}")
TMP=$(mktemp -d)
trap 'rm -rf "$TMP"' EXIT

git -C "$ROOT" archive HEAD app bootstrap/app.php bootstrap/providers.php config database resources routes public | tar -x -C "$TMP"
rm -f "$TMP/database/database.sqlite" "$TMP/public/index.php" "$TMP/public/.htaccess"
mkdir -p "$TMP/web/core"
mv "$TMP"/public/* "$TMP/web/"
mv "$TMP"/app "$TMP"/bootstrap "$TMP"/config "$TMP"/database "$TMP"/resources "$TMP"/routes "$TMP/web/core/"
cp "$ROOT/deploy/import-telegram.php" "$TMP/web/"
mkdir -p "$(dirname "$OUT")"
rm -f "$OUT"
(cd "$TMP/web" && zip -qr "$OUT" .)
echo "Готово: $OUT ($(du -h "$OUT" | cut -f1))"
