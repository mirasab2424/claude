<?php

namespace Tests\Feature;

use App\Enums\GoalStatus;
use App\Models\User;
use App\Services\Coach;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CoachTest extends TestCase
{
    use RefreshDatabase;

    private function daily(User $user, string $name, array $values, bool $duration = false): void
    {
        $m = $user->metrics()->create(['name' => $name, 'is_duration' => $duration]);
        foreach ($values as $i => $v) {
            $m->entries()->create(['date' => CarbonImmutable::parse('2026-11-01')->addDays($i)->toDateString(), 'value' => $v]);
        }
    }

    public function test_warns_about_short_sleep_and_praises_steps(): void
    {
        $user = User::factory()->create();
        $this->daily($user, 'Сон', [5 * 3600, 5.5 * 3600, 6 * 3600, 5 * 3600], duration: true);
        $this->daily($user, 'Шаги', [4000, 16529, 6000, 7000]);

        $coach = Coach::for($user, CarbonImmutable::parse('2026-11-06'));
        $tips = collect($coach->tips(10))->keyBy('title');

        $this->assertSame('warn', $tips['Мало сна']['tone']);
        $this->assertStringContainsString('5 ч 23 мин', $tips['Мало сна']['text']);
        $this->assertStringContainsString('16 529', $tips['Отличный день']['text']);
        $this->assertSame('warn', $coach->tips()[0]['tone']); // предупреждения первыми
        $this->assertSame('2026-11-04', $coach->healthAsOf()->toDateString());
    }

    public function test_old_health_data_gives_no_advice(): void
    {
        $user = User::factory()->create();
        $this->daily($user, 'Сон', [4 * 3600, 4 * 3600, 4 * 3600], duration: true);

        $titles = array_column(Coach::for($user, CarbonImmutable::parse('2027-01-01'))->tips(10), 'title');

        $this->assertNotContains('Мало сна', $titles);
    }

    public function test_overdue_goal_personal_best_and_mood(): void
    {
        $user = User::factory()->create();
        $user->goals()->create(['title' => 'Пробежать 10 км', 'status' => GoalStatus::InProgress, 'due_date' => '2026-11-01']);
        $m = $user->metrics()->create(['name' => 'Подтягивания']);
        foreach (['2026-10-01' => 10, '2026-10-15' => 12, '2026-11-05' => 15] as $d => $v) {
            $m->entries()->create(['date' => $d, 'value' => $v]);
        }

        $coach = Coach::for($user, CarbonImmutable::parse('2026-11-06'));
        $tips = collect($coach->tips(10))->keyBy('title');

        $this->assertStringContainsString('Пробежать 10 км', $tips['Просрочено: 1']['text']);
        $this->assertStringContainsString('Подтягивания: 15', $tips['Личный рекорд']['text']);
        $this->assertSame('calm', $coach->mood($coach->tips()));
    }

    public function test_cabinet_shows_coach(): void
    {
        $this->seed(\Database\Seeders\DatabaseSeeder::class);
        $user = User::factory()->create();

        $this->actingAs($user)->get('/cabinet')->assertOk()->assertSee('Тренер')->assertSee('Нет активной цели')->assertSee('/cabinet/goals', false)->assertSee('Новое в кабинете');
    }

    public function test_metric_target_tip(): void
    {
        $user = User::factory()->create();
        $run = $user->metrics()->create(['name' => 'Бег 3 км', 'is_duration' => true, 'direction' => 'down', 'target' => 780]);
        $run->entries()->create(['date' => '2026-10-01', 'value' => 832]);
        $run->entries()->create(['date' => '2026-11-01', 'value' => 821]);

        $tips = collect(Coach::for($user, CarbonImmutable::parse('2026-11-06'))->tips(10))->keyBy('title');

        $this->assertSame('Бег 3 км: лучший результат 13:41, цель 13:00 — осталось 0:41.', $tips['До цели по метрике']['text']);
    }

    public function test_habit_rules_praise_a_week_warn_of_risk_and_notice_a_seven_day_gap(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-25'));
        $user = User::factory()->create();
        $steady = $user->habits()->create(['name' => 'Зарядка']);
        $risk = $user->habits()->create(['name' => 'Чтение']);
        $inactive = $user->habits()->create(['name' => 'Подтягивания']);
        $today = CarbonImmutable::parse('2026-11-15');
        $this->assertNotContains('Привычка без отметок', array_column(Coach::for($user, $today)->tips(30), 'title'));
        foreach (range(9, 15) as $day) {
            $steady->checks()->create(['date' => "2026-11-$day"]);
        }
        foreach (range(12, 14) as $day) {
            $risk->checks()->create(['date' => "2026-11-$day"]);
        }
        $inactive->checks()->create(['date' => '2026-11-08']);
        $tips = collect(Coach::for($user, $today)->tips(30))->keyBy('title');

        $this->assertStringContainsString('7 дней', $tips['Привычка держится']['text']);
        $this->assertSame(Coach::WARN, $tips['Серия привычки под угрозой']['tone']);
        $this->assertStringContainsString('7 дн.', $tips['Привычка без отметок']['text']);
    }

    public function test_log_trends_require_three_ratings_in_each_week_and_gap_requires_history(): void
    {
        $user = User::factory()->create();
        $today = CarbonImmutable::parse('2026-11-15');
        $history = fn () => collect(Coach::for($user, $today)->tips(30))->keyBy('title');
        $this->assertFalse($history()->has('Пауза в дневнике'));
        foreach ([3, 4, 5] as $day) {
            $user->dayLogs()->create(['date' => "2026-11-$day", 'mood' => 5, 'energy' => 2]);
        }
        $this->assertStringContainsString('10 дн.', $history()['Пауза в дневнике']['text']);
        foreach ([10, 11] as $day) {
            $user->dayLogs()->create(['date' => "2026-11-$day", 'mood' => 3, 'energy' => 4]);
        }
        $this->assertFalse($history()->has('Настроение ниже'));
        $this->assertFalse($history()->has('Энергия выше'));
        $user->dayLogs()->create(['date' => '2026-11-13', 'mood' => 3, 'energy' => 4]);
        $tips = $history();
        $this->assertStringContainsString('2,0 из 5', $tips['Настроение ниже']['text']);
        $this->assertSame(Coach::GOOD, $tips['Энергия выше']['tone']);
        $this->assertFalse($tips->has('Пауза в дневнике'));
    }

    public function test_task_load_and_idle_goal_tips_use_the_supplied_date(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-01'));
        $today = CarbonImmutable::parse('2026-11-15');
        $user = User::factory()->create();
        $tips = fn ($day) => collect(Coach::for($user, $day)->tips(30))->keyBy('title');
        $this->assertFalse($tips($today)->has('Запланируйте шаг'));
        $user->goals()->create(['title' => 'Старая цель', 'status' => GoalStatus::InProgress]);
        $this->assertFalse($tips($today->subDay())->has('Цель без движения'));
        $this->assertStringContainsString('14 дн.', $tips($today)['Цель без движения']['text']);
        $this->assertTrue($tips($today)->has('Запланируйте шаг'));
        foreach (range(1, 7) as $i) {
            $user->goals()->create(['title' => 'Задача '.$i, 'due_date' => $today]);
        }
        $this->assertFalse($tips($today)->has('Много задач на сегодня'));
        $user->goals()->create(['title' => 'Задача 8', 'due_date' => $today]);
        $this->assertSame(Coach::WARN, $tips($today)['Много задач на сегодня']['tone']);
    }

    public function test_steps_compare_distinct_days_and_look_for_a_consecutive_run(): void
    {
        $user = User::factory()->create();
        $today = CarbonImmutable::parse('2026-11-14');
        $metric = $user->metrics()->create(['name' => 'Шаги']);
        foreach ([1 => 15000, 2 => 15000, 8 => 11000, 9 => 11000, 11 => 11000] as $d => $v) {
            $metric->entries()->create(['date' => "2026-11-$d", 'value' => $v]);
        }
        $tips = fn () => collect(Coach::for($user, $today)->tips(30))->keyBy('title');
        $this->assertFalse($tips()->has('Шагов стало меньше'));
        $this->assertFalse($tips()->has('Серия по шагам'));
        $metric->entries()->create(['date' => '2026-11-03', 'value' => 15000]);
        $metric->entries()->create(['date' => '2026-11-10', 'value' => 11000]);
        $this->assertStringContainsString('27 %', $tips()['Шагов стало меньше']['text']);
        $this->assertStringContainsString('4 дн.', $tips()['Серия по шагам']['text']);
    }

    public function test_sleep_variability_requires_three_nights_and_a_range_above_ninety_minutes(): void
    {
        $user = User::factory()->create();
        $today = CarbonImmutable::parse('2026-11-05');
        $this->daily($user, 'Сон', [6 * 3600, 8 * 3600], duration: true);
        $tips = fn () => collect(Coach::for($user->fresh(), $today)->tips(30))->keyBy('title');
        $this->assertFalse($tips()->has('Неровный режим сна'));
        $user->metrics()->firstOrFail()->entries()->create(['date' => '2026-11-03', 'value' => 25200]);
        $this->assertStringContainsString('2 ч 00 мин', $tips()['Неровный режим сна']['text']);
    }
}
