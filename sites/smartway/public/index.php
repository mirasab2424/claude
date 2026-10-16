<?php
// SMART WAY: все запросы, кроме статических файлов, идут сюда (см. .htaccess).
// На хостинге код лежит в htdocs/app, локально — в ../app.
$root = is_dir(__DIR__ . '/app') ? __DIR__ . '/app' : dirname(__DIR__) . '/app';

if (PHP_SAPI === 'cli-server' && is_file(__DIR__ . parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH))) {
    return false;
}

require $root . '/vendor/autoload.php';

(new Smart\App($root, __DIR__))->run();
