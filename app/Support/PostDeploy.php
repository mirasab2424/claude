<?php

namespace App\Support;

use App\Models\User;
use Database\Seeders\TelegramDiarySeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Шаги после автодеплоя на хостинг без консоли (InfinityFree).
 * GitHub Actions кладёт в корень приложения файл deploy-version с хешем коммита.
 * Первый запрос после обновления видит новую версию и один раз применяет
 * миграции (и, если ещё не было, импортирует дневник из Telegram).
 */
class PostDeploy
{
    public static function runIfNeeded(): void
    {
        $versionFile = base_path('deploy-version');
        if (! is_file($versionFile)) {
            return;
        }
        $version = trim((string) file_get_contents($versionFile));
        $doneFile = storage_path('framework/deployed-version');
        if ($version === '' || (is_file($doneFile) && trim((string) file_get_contents($doneFile)) === $version)) {
            return;
        }

        // Блокировка, чтобы два одновременных запроса не запустили миграции дважды.
        $lock = fopen(storage_path('framework/post-deploy.lock'), 'c');
        if (! $lock || ! flock($lock, LOCK_EX | LOCK_NB)) {
            return;
        }

        try {
            Artisan::call('migrate', ['--force' => true]);
            self::importTelegramDiaryOnce();
            Artisan::call('view:clear');
            file_put_contents($doneFile, $version);
            Log::info("Post-deploy $version: ok");
        } catch (Throwable $e) {
            Log::error("Post-deploy $version failed: ".$e->getMessage());
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** Дневник импортируется один раз; если он уже есть (в т.ч. залит вручную) — только ставим отметку. */
    public static function importTelegramDiaryOnce(): void
    {
        $marker = storage_path('framework/telegram-imported');
        if (is_file($marker)) {
            return;
        }
        $admin = User::where('is_admin', true)->orderBy('id')->first();
        if ($admin && ! $admin->goals()->where('link', 'like', 'https://t.me/successM2025/%')->exists()) {
            app(TelegramDiarySeeder::class)->run($admin);
        }
        file_put_contents($marker, date('c'));
    }
}
