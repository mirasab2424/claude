<?php
// Одноразовый импорт дневника из Telegram на хостинге без консоли.
// Положить в htdocs, открыть /import-telegram.php?confirm=yes, после успеха УДАЛИТЬ файл.
// Заменяет все цели, метрики и места администратора данными из core/database/data/telegram.
header('Content-Type: text/plain; charset=utf-8');
ini_set('display_errors', '1');
error_reporting(E_ALL);
set_time_limit(120);

if (($_GET['confirm'] ?? '') !== 'yes') {
    exit("Этот скрипт заменит цели, метрики и места администратора дневником из Telegram.\nЧтобы запустить, откройте: ".strtok($_SERVER['REQUEST_URI'], '?')."?confirm=yes\n");
}

$core = __DIR__.'/core';
require "$core/vendor/autoload.php";
$app = require "$core/bootstrap/app.php";
$app->usePublicPath(__DIR__);
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

try {
    echo "1. Обновляю структуру базы…\n";
    Illuminate\Support\Facades\Artisan::call('migrate', ['--force' => true]);
    echo Illuminate\Support\Facades\Artisan::output();

    $user = App\Models\User::where('is_admin', true)->orderBy('id')->firstOrFail();
    // Защита от повторного запуска: иначе он сотрёт то, что вы добавили после импорта.
    if ($user->goals()->where('link', 'like', 'https://t.me/successM2025/%')->exists() && ($_GET['force'] ?? '') !== 'yes') {
        exit("\nДневник уже импортирован, ничего не меняю. Удалите этот файл из htdocs.\n");
    }
    echo "2. Импортирую дневник для {$user->name} ({$user->email})…\n";
    $app->make(Database\Seeders\TelegramDiarySeeder::class)->run($user);

    Illuminate\Support\Facades\Artisan::call('view:clear');
    echo "\nГотово: целей {$user->goals()->count()}, метрик {$user->metrics()->count()}.\n";
    echo "Страница: /u/{$user->username}\n\nТеперь УДАЛИТЕ этот файл (import-telegram.php) из htdocs.\n";
} catch (Throwable $e) {
    echo "\nОШИБКА: ".get_class($e).': '.$e->getMessage()."\n  в ".$e->getFile().':'.$e->getLine()."\n";
}
