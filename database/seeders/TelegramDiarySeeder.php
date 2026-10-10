<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Goal;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

/**
 * Импорт дневника из Telegram-канала t.me/successM2025 (database/data/telegram/diary.json).
 * Заменяет все цели, метрики и места пользователя — запускать осознанно.
 */
class TelegramDiarySeeder extends Seeder
{
    public function run(User $user): void
    {
        $dir = database_path('data/telegram');
        $data = json_decode(File::get("$dir/diary.json"), true, flags: JSON_THROW_ON_ERROR);

        DB::transaction(function () use ($user, $data, $dir) {
            $user->goals()->delete();
            $user->metrics()->delete();
            $user->places()->delete();

            $user->forceFill([
                'tagline' => $data['profile']['tagline'],
                'bio' => $data['profile']['bio'],
                'telegram' => $data['profile']['channel'],
                'city' => null,
                'is_public' => true,
            ])->save();

            $categories = Category::pluck('id', 'name');
            $disk = Storage::disk('public');

            foreach ($data['goals'] as $row) {
                $photo = null;
                if ($row['photo']) {
                    $photo = 'goals/telegram-'.$row['photo'];
                    $disk->put($photo, File::get("$dir/photos/{$row['photo']}"));
                }
                $done = $row['completed_at'] ? CarbonImmutable::parse($row['completed_at']) : null;

                $goal = new Goal([
                    'user_id' => $user->id,
                    'category_id' => $categories[$row['category']] ?? null,
                    'title' => $row['title'],
                    'importance' => $row['importance'],
                    'horizon' => $row['horizon'],
                    'status' => $row['status'],
                    'progress' => $row['progress'] ?? 0,
                    'start_date' => $row['start_date'] ?? $done?->toDateString(),
                    'due_date' => $done?->toDateString(),
                    'completed_at' => $done,
                    'notes' => $row['notes'],
                    'retrospective' => $row['retrospective'],
                    'photo' => $photo,
                    'link' => $row['link'],
                    'is_public' => true,
                ]);
                $goal->created_at = $done ?? CarbonImmutable::parse($row['start_date'] ?? 'now');
                $goal->save();
            }

            foreach ($data['metrics'] as $row) {
                $metric = $user->metrics()->create([
                    'category_id' => $categories['Спорт'] ?? null,
                    'name' => $row['name'],
                    'unit' => $row['unit'],
                    'direction' => $row['direction'],
                    'is_duration' => $row['duration'],
                    'is_public' => true,
                ]);
                if ($row['name'] === 'Вес') {
                    $metric->update(['category_id' => $categories['Здоровье'] ?? null]);
                }
                $metric->entries()->createMany(array_map(fn ($e) => [
                    'date' => $e['date'],
                    'value' => $e['value'],
                    'note' => $e['note'],
                ], $row['entries']));
            }
        });
    }
}
