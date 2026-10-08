<?php

namespace Database\Seeders;

use App\Enums\GoalStatus;
use App\Models\Category;
use App\Models\Goal;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Arr;

/** Демо-данные, чтобы графики не были пустыми. Удаляются из админки вместе с записями. */
class DemoDataSeeder extends Seeder
{
    public function run(User $user): void
    {
        if ($user->goals()->exists()) {
            return;
        }

        mt_srand(2025);
        $cat = Category::pluck('id', 'name');
        $today = CarbonImmutable::today();

        $year = Goal::create([
            'user_id' => $user->id, 'category_id' => $cat['Спорт'], 'title' => 'Пробежать полумарафон',
            'importance' => 'must', 'horizon' => 'year', 'status' => 'in_progress', 'progress' => 60,
            'start_date' => $today->startOfYear(), 'due_date' => $today->endOfYear(),
            'notes' => "План: 3 пробежки в неделю, одна длинная.\nНе забывать про растяжку.",
        ]);
        Goal::create([
            'user_id' => $user->id, 'category_id' => $cat['Знание'], 'title' => 'Прочитать 24 книги',
            'importance' => 'need', 'horizon' => 'year', 'status' => 'in_progress', 'progress' => 45,
            'due_date' => $today->endOfYear(),
        ]);
        Goal::create([
            'user_id' => $user->id, 'category_id' => $cat['Навык'], 'title' => 'Выучить английский до B2',
            'importance' => 'must', 'horizon' => 'year', 'status' => 'in_progress', 'progress' => 30,
            'due_date' => $today->endOfYear(),
        ]);
        Goal::create([
            'user_id' => $user->id, 'category_id' => $cat['Финансы'], 'title' => 'Собрать финансовую подушку на 6 месяцев',
            'importance' => 'need', 'horizon' => 'year', 'status' => 'planned', 'progress' => 15,
        ]);
        Goal::create([
            'user_id' => $user->id, 'category_id' => $cat['Работа'], 'title' => 'Запустить личный сайт success',
            'importance' => 'must', 'horizon' => 'month', 'status' => 'in_progress', 'progress' => 70,
            'due_date' => $today->endOfMonth(), 'notes' => 'Laravel + Filament, 3D-логотип на Three.js',
        ]);
        Goal::create([
            'user_id' => $user->id, 'category_id' => $cat['Спорт'], 'title' => 'Пробежать 10 км без остановки',
            'importance' => 'need', 'horizon' => 'month', 'status' => 'in_progress', 'progress' => 80,
            'parent_id' => $year->id, 'due_date' => $today->addDays(12),
        ]);
        Goal::create([
            'user_id' => $user->id, 'category_id' => $cat['Знание'], 'title' => 'Курс по Three.js',
            'importance' => 'want', 'horizon' => 'month', 'status' => 'planned',
        ]);
        Goal::create([
            'user_id' => $user->id, 'category_id' => $cat['Здоровье'], 'title' => 'Спать 7+ часов 5 дней из 7',
            'importance' => 'need', 'horizon' => 'week', 'status' => 'in_progress', 'progress' => 40,
            'due_date' => $today->endOfWeek(),
        ]);
        Goal::create([
            'user_id' => $user->id, 'category_id' => $cat['Работа'], 'title' => 'Сдать макет главной клиенту',
            'importance' => 'must', 'horizon' => 'week', 'status' => 'in_progress', 'progress' => 50,
            'due_date' => $today->subDays(2),
        ]);
        Goal::create([
            'user_id' => $user->id, 'category_id' => $cat['Навык'], 'title' => '30 минут английского',
            'importance' => 'need', 'horizon' => 'day', 'status' => 'planned', 'due_date' => $today,
        ]);
        Goal::create([
            'user_id' => $user->id, 'category_id' => $cat['Спорт'], 'title' => 'Тренировка: интервалы 6×400 м',
            'importance' => 'want', 'horizon' => 'day', 'status' => 'planned', 'due_date' => $today,
            'parent_id' => $year->id,
        ]);

        // История за год — выполненные и проваленные задачи.
        $templates = [
            ['Спорт', 'Пробежка 5 км', 'day'], ['Спорт', 'Силовая тренировка', 'day'], ['Знание', 'Прочитать книгу', 'month'],
            ['Навык', 'Урок английского', 'day'], ['Работа', 'Закрыть спринт', 'week'], ['Здоровье', 'Неделя без сахара', 'week'],
            ['Финансы', 'Отложить 10% дохода', 'month'], ['Навык', 'Пет-проект: новая фича', 'week'], ['Знание', 'Статья по архитектуре', 'day'],
        ];
        $lessons = [
            'done' => [
                'Лучше всего работает, когда задача стоит в календаре на конкретное время.',
                'Разбил на маленькие шаги, так оказалось намного проще.',
                'Помогло делать с утра, пока нет других дел.',
            ],
            'failed' => [
                'Сорвал из-за командировки. Вывод: заранее планировать запасной вариант.',
                'Слишком амбициозно для такого срока, в следующий раз брать меньше.',
            ],
        ];
        for ($d = 360; $d >= 1; $d--) {
            $chance = 0.25 + 0.35 * (1 - $d / 360); // активность растёт со временем
            for ($n = 0; $n < 3; $n++) {
                if (mt_rand() / mt_getrandmax() > $chance * (1 - $n * 0.4)) {
                    continue;
                }
                [$c, $title, $horizon] = $templates[array_rand($templates)];
                $date = $today->subDays($d)->setTime(mt_rand(7, 22), mt_rand(0, 59));
                $failed = mt_rand(1, 100) <= 14;
                $goal = new Goal([
                    'user_id' => $user->id, 'category_id' => $cat[$c], 'title' => $title, 'horizon' => $horizon,
                    'importance' => ['must', 'need', 'need', 'want'][mt_rand(0, 3)],
                    'status' => $failed ? GoalStatus::Failed : GoalStatus::Done,
                    'progress' => $failed ? mt_rand(10, 70) : 100,
                    'due_date' => $date->toDateString(),
                    'completed_at' => $date,
                    'retrospective' => mt_rand(1, 100) <= 5 ? Arr::random($lessons[$failed ? 'failed' : 'done']) : null,
                ]);
                $goal->created_at = $date->subDays(['day' => 0, 'week' => 6, 'month' => 25][$horizon]);
                $goal->save();
            }
        }

        // Метрики.
        $weight = $user->metrics()->create(['name' => 'Вес', 'unit' => 'кг', 'direction' => 'down', 'target' => 75, 'category_id' => $cat['Здоровье']]);
        $run = $user->metrics()->create(['name' => 'Длинная пробежка', 'unit' => 'км', 'direction' => 'up', 'target' => 21.1, 'category_id' => $cat['Спорт']]);
        $books = $user->metrics()->create(['name' => 'Прочитано книг', 'unit' => 'шт.', 'direction' => 'up', 'target' => 24, 'category_id' => $cat['Знание']]);
        $sleep = $user->metrics()->create(['name' => 'Средний сон', 'unit' => 'ч', 'direction' => 'up', 'target' => 7.5, 'category_id' => $cat['Здоровье']]);
        for ($w = 26; $w >= 0; $w--) {
            $date = $today->subWeeks($w)->toDateString();
            $i = 26 - $w;
            $weight->entries()->create(['date' => $date, 'value' => round(86 - $i * 0.35 + mt_rand(-6, 6) / 10, 1)]);
            $run->entries()->create(['date' => $date, 'value' => round(min(21.1, 4 + $i * 0.5 + mt_rand(-10, 10) / 10), 1)]);
            $sleep->entries()->create(['date' => $date, 'value' => round(7.1 - $i * 0.02 + mt_rand(-4, 4) / 10, 1)]);
        }
        foreach (range(1, 10) as $m => $n) {
            $books->entries()->create(['date' => $today->startOfYear()->addMonths($m)->toDateString(), 'value' => $n]);
        }

        // Карта мест.
        $places = [
            ['Астана, Байтерек', 51.1283, 71.4305, 5, 'Дом. Отсюда всё начинается.'],
            ['Алматы, Медеу', 43.1576, 77.0590, 5, 'Каток и горы — лучшее сочетание.'],
            ['Большое Алматинское озеро', 43.0506, 76.9853, 5, 'Бирюзовая вода, стоит подъёма.'],
            ['Бурабай', 53.0833, 70.3000, 4, 'Сосны, озёра и чистый воздух.'],
            ['Шымкент', 42.3417, 69.5901, 4, null],
            ['Стамбул', 41.0082, 28.9784, 5, 'Первая поездка за границу в этом году.'],
            ['Чарынский каньон', 43.3530, 79.0796, 5, 'Долина замков на закате.'],
        ];
        foreach ($places as $i => [$title, $lat, $lng, $rating, $desc]) {
            $user->places()->create([
                'title' => $title, 'latitude' => $lat, 'longitude' => $lng, 'rating' => $rating,
                'description' => $desc, 'category_id' => $cat['Путешествия'],
                'visited_at' => $today->subDays(20 + $i * 47)->toDateString(),
            ]);
        }
    }
}
