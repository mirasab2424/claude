<?php

namespace Tests\Feature;

use App\Models\Goal;
use App\Models\Metric;
use App\Models\User;
use App\Services\UserStats;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserStatsTest extends TestCase
{
    use RefreshDatabase;

    private function goal(User $user, array $attrs): Goal
    {
        return Goal::create(['user_id' => $user->id, 'title' => 'g'] + $attrs);
    }

    public function test_summary_counts_success_rate_xp_and_streak(): void
    {
        $user = User::factory()->create();
        // Две выполненные: «необходимо на месяц» (3×10) и «хочется на день» (1×1) = 31 XP.
        $this->goal($user, ['status' => 'done', 'importance' => 'must', 'horizon' => 'month', 'completed_at' => now()]);
        $this->goal($user, ['status' => 'done', 'importance' => 'want', 'horizon' => 'day', 'completed_at' => now()->subDay()]);
        $this->goal($user, ['status' => 'failed']);
        $this->goal($user, ['status' => 'dropped']);
        $this->goal($user, ['status' => 'planned', 'due_date' => now()->subDays(3)]);

        $s = UserStats::for($user)->summary();

        $this->assertSame(5, $s['total']);
        $this->assertSame(67, $s['success_rate']); // 2 / (2 + 1), отменённые не считаются
        $this->assertSame(31, $s['xp']);
        $this->assertSame(2, $s['level']);         // 25 XP → 2-й уровень
        $this->assertSame(2, $s['streak']);
        $this->assertSame(1, $s['overdue']);
    }

    public function test_metric_trend_respects_direction(): void
    {
        $user = User::factory()->create();
        $weight = Metric::create(['user_id' => $user->id, 'name' => 'Вес', 'direction' => 'down']);
        $weight->entries()->create(['date' => '2026-01-01', 'value' => 90]);
        $weight->entries()->create(['date' => '2026-02-01', 'value' => 85]);

        $this->assertSame('progress', $weight->trend()['state']);
        $this->assertEquals(-5, $weight->trend()['delta']);

        $steps = Metric::create(['user_id' => $user->id, 'name' => 'Шаги', 'direction' => 'up']);
        $steps->entries()->create(['date' => '2026-01-01', 'value' => 9000]);
        $steps->entries()->create(['date' => '2026-02-01', 'value' => 6000]);

        $this->assertSame('regress', $steps->trend()['state']);
    }
}
