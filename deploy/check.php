<?php
// Диагностика хостинга. Положить в htdocs, открыть /check.php, потом УДАЛИТЬ.
header('Content-Type: text/plain; charset=utf-8');
ini_set('display_errors', '1');
error_reporting(E_ALL);

echo "PHP: ".PHP_VERSION.(version_compare(PHP_VERSION, '8.3.0', '>=') ? " — ок\n" : " — НУЖНА 8.3 или новее!\n");

echo "\nРасширения:\n";
foreach (['pdo_sqlite', 'sqlite3', 'mbstring', 'intl', 'openssl', 'tokenizer', 'xml', 'dom', 'ctype', 'fileinfo', 'zip', 'gd', 'curl'] as $ext) {
    echo "  $ext: ".(extension_loaded($ext) ? 'ок' : 'НЕТ')."\n";
}

echo "\nФайлы:\n";
$core = __DIR__.'/core';
foreach (['vendor/autoload.php', 'bootstrap/app.php', '.env', 'database/database.sqlite', 'vendor/filament/filament/src/FilamentServiceProvider.php'] as $f) {
    echo "  core/$f: ".(is_file("$core/$f") ? 'есть' : 'НЕТ')."\n";
}
foreach (['storage', 'storage/framework/views', 'storage/framework/sessions', 'storage/logs', 'bootstrap/cache', 'database'] as $d) {
    echo "  core/$d: ".(is_dir("$core/$d") ? (is_writable("$core/$d") ? 'запись ок' : 'НЕТ ЗАПИСИ') : 'НЕТ ПАПКИ')."\n";
}
$count = 0;
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator("$core/vendor", FilesystemIterator::SKIP_DOTS)) as $x) {
    $count++;
}
echo "  файлов в core/vendor: $count (должно быть около 17 300)\n";

$disabled = ini_get('disable_functions');
echo "\nОтключённые функции: ".($disabled ?: 'нет')."\n";

echo "\nЗапуск Laravel:\n";
try {
    require "$core/vendor/autoload.php";
    $app = require "$core/bootstrap/app.php";
    $app->usePublicPath(__DIR__);
    $kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
    $response = $kernel->handle(Illuminate\Http\Request::create('/'));
    echo "  главная отвечает кодом ".$response->getStatusCode()."\n";
} catch (Throwable $e) {
    echo "  ОШИБКА: ".get_class($e).": ".$e->getMessage()."\n  в ".$e->getFile().':'.$e->getLine()."\n";
}

$log = "$core/storage/logs/laravel.log";
if (is_file($log)) {
    echo "\nПоследняя ошибка из лога:\n";
    $text = file_get_contents($log);
    $pos = strrpos($text, '] production.ERROR');
    echo '  '.substr($text, $pos === false ? -1500 : $pos, 1500)."\n";
}
