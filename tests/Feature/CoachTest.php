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

        $this->actingAs($user)->get('/cabinet')->assertOk()->assertSee('Тренер')->assertSee('Нет активной цели');
    }
}
