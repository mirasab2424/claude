#!/usr/bin/env bash
# Собирает сайт для shared-хостинга без SSH и Composer (например, InfinityFree).
#
#   APP_URL=http://miras.page.gd deploy/build-shared-hosting.sh [папка-результата]
#
# Результат: папка htdocs/ (её содержимое заливается в htdocs на хостинге)
# и та же папка, разбитая на zip-архивы до 9 МБ (лимит загрузки файлового менеджера).
# Ядро Laravel лежит в htdocs/core и закрыто от доступа через .htaccess.
set -euo pipefail

ROOT=$(cd "$(dirname "$0")/.." && pwd)
OUT=$(realpath -m "${1:-$ROOT/build/shared-hosting}")
APP_URL=${APP_URL:-http://localhost}
WEB="$OUT/htdocs"
CORE="$WEB/core"

rm -rf "$OUT"
mkdir -p "$CORE"
git -C "$ROOT" archive HEAD | tar -x -C "$CORE"
cd "$CORE"

composer install --no-dev --optimize-autoloader --no-interaction --prefer-dist --quiet
# Если Composer поставил пакеты из исходников, убираем историю git и тесты.
find vendor -name .git -type d -prune -exec rm -rf {} +
find vendor -mindepth 3 -maxdepth 3 -type d \( -name tests -o -name Tests -o -name test_files -o -name docs -o -name .github \) -prune -exec rm -rf {} +
composer dump-autoload --optimize --no-dev --quiet
rm -rf deploy tests .github phpunit.xml .editorconfig .styleci.yml CLAUDE.md

ADMIN_PASSWORD=${ADMIN_PASSWORD:-$(php -r 'echo bin2hex(random_bytes(6));')}
cat > .env <<ENV
APP_NAME="Success"
APP_ENV=production
APP_KEY=
APP_DEBUG=false
APP_URL=$APP_URL
APP_LOCALE=ru
APP_FALLBACK_LOCALE=en

LOG_CHANNEL=single
LOG_LEVEL=error

DB_CONNECTION=sqlite
SESSION_DRIVER=database
SESSION_LIFETIME=10080
CACHE_STORE=database
QUEUE_CONNECTION=sync
FILESYSTEM_DISK=local
PUBLIC_DISK_IN_WEBROOT=true
MAIL_MAILER=log

ADMIN_NAME=${ADMIN_NAME:-Miras}
ADMIN_EMAIL=${ADMIN_EMAIL:-admin@example.com}
ADMIN_PASSWORD=$ADMIN_PASSWORD
ENV
php artisan key:generate --force --quiet
touch database/database.sqlite
php artisan migrate --force --seed --quiet
# Пароль в .env больше не нужен: он уже записан в базу (в виде хеша).
sed -i 's/^ADMIN_PASSWORD=.*/ADMIN_PASSWORD=/' .env
php artisan filament:assets --quiet

# public/ становится корнем сайта (htdocs), ядро — в htdocs/core.
shopt -s dotglob
mv public/* "$WEB/"
rmdir public
rm -f "$WEB/storage"
mkdir -p "$WEB/storage"

cat > "$WEB/index.php" <<'PHP'
<?php

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

$core = __DIR__.'/core';

if (file_exists($maintenance = $core.'/storage/framework/maintenance.php')) {
    require $maintenance;
}

require $core.'/vendor/autoload.php';

/** @var Application $app */
$app = require_once $core.'/bootstrap/app.php';
$app->usePublicPath(__DIR__);

$app->handleRequest(Request::capture());
PHP

# Закрываем ядро (код, .env, база) от доступа из браузера.
cat > "$CORE/.htaccess" <<'HT'
<IfModule mod_authz_core.c>
    Require all denied
</IfModule>
<IfModule !mod_authz_core.c>
    Order deny,allow
    Deny from all
</IfModule>
HT
sed -i 's|    RewriteEngine On|    RewriteEngine On\n\n    # Ядро Laravel недоступно снаружи\n    RewriteRule ^core(/\|$) - [F,L]|' "$WEB/.htaccess"

# Архивы до 9 МБ для загрузки через файловый менеджер хостинга.
python3 - "$WEB" "$OUT" <<'PY'
import os, sys, zipfile
web, out = sys.argv[1], sys.argv[2]
files = []
for d, _, names in os.walk(web):
    for n in names:
        p = os.path.join(d, n)
        files.append((os.path.relpath(p, web), os.path.getsize(p)))
files.sort()
parts, cur, size = [], [], 0
for rel, s in files:
    if cur and size + s > 9 * 1024 * 1024 * 2.5:  # текст сжимается примерно в 2.5 раза
        parts.append(cur); cur, size = [], 0
    cur.append(rel); size += s
parts.append(cur)
for i, part in enumerate(parts, 1):
    with zipfile.ZipFile(os.path.join(out, f'site-part{i}.zip'), 'w', zipfile.ZIP_DEFLATED) as z:
        for rel in part:
            z.write(os.path.join(web, rel), rel)
PY

echo "Готово: $OUT"
echo "Файлов: $(find "$WEB" -type f | wc -l)"
ls -lh "$OUT"/*.zip | awk '{print $5, $9}'
echo "Вход в админку: ${ADMIN_EMAIL:-admin@example.com} / $ADMIN_PASSWORD"
