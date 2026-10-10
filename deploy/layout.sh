#!/usr/bin/env bash
# Общая раскладка для хостинга без SSH: public/ → корень сайта, остальное → core/ (закрыто .htaccess).
# Использование: source deploy/layout.sh; prepare_webroot "$WEB"   (в "$WEB/core" лежит копия проекта)

prepare_webroot() {
local WEB="$1"
local CORE="$WEB/core"

# public/ становится корнем сайта (htdocs), ядро — в htdocs/core.
shopt -s dotglob
mv "$CORE"/public/* "$WEB/"
rmdir "$CORE/public"
rm -f "$WEB/storage"
mkdir -p "$WEB/storage"
shopt -u dotglob

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
}
