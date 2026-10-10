<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $categories = [
            ['Спорт', '🏃', '#3987e5'],
            ['Знание', '📚', '#9085e9'],
            ['Навык', '🛠', '#d95926'],
            ['Здоровье', '❤️', '#d55181'],
            ['Работа', '💼', '#c98500'],
            ['Финансы', '💰', '#199e70'],
            ['Путешествия', '✈️', '#e66767'],
        ];
        foreach ($categories as $i => [$name, $icon, $color]) {
            Category::updateOrCreate(['name' => $name], ['icon' => $icon, 'color' => $color, 'sort_order' => $i]);
        }

        $admin = User::firstOrCreate(
            ['email' => env('ADMIN_EMAIL', 'admin@example.com')],
            [
                'name' => env('ADMIN_NAME', 'Miras'),
                'username' => 'miras',
                'password' => env('ADMIN_PASSWORD', 'password'),
            ],
        );
        $admin->forceFill(['is_admin' => true])->save();

        // Дневник из Telegram-канала: тренировки, метрики, достижения.
        $this->call(TelegramDiarySeeder::class, parameters: ['user' => $admin]);
    }
}
