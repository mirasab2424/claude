<?php

namespace Tests\Feature;

use App\Enums\GoalStatus;
use App\Enums\Horizon;
use App\Filament\Cabinet\Pages\MonthReviewPage;
use App\Models\User;
use App\Services\MonthReview;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class MonthReviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_month_totals_comparison_activity_and_private_scope(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-01 12:00'));
        $user = User::factory()->create();
        $habit = $user->habits()->create(['name' => 'Зарядка', 'days_per_week' => 7]);
        $other = User::factory()->create();
        $this->travelTo(CarbonImmutable::parse('2026-11-03 12:00'));
        $habit->checks()->create(['date' => '2026-11-01']);
        $habit->checks()->create(['date' => '2026-11-02']);
        $user->goals()->create(['title' => 'Работа', 'status' => GoalStatus::Done, 'horizon' => Horizon::Day, 'completed_at' => '2026-11-02']);
        $user->goals()->create(['title' => 'Цель', 'status' => GoalStatus::Done, 'horizon' => Horizon::Month, 'completed_at' => '2026-11-02']);
        $user->goals()->create(['title' => 'Старая цель', 'status' => GoalStatus::Done, 'horizon' => Horizon::Month, 'completed_at' => '2026-10-31']);
        $user->dayLogs()->create(['date' => '2026-11-02', 'mood' => 4, 'energy' => 3]);
        $user->metrics()->create(['name' => 'Шаги'])->entries()->create(['date' => '2026-11-02', 'value' => 6000]);
        $user->metrics()->create(['name' => 'Сон', 'is_duration' => true])->entries()->create(['date' => '2026-11-03', 'value' => 25200]);
        $other->metrics()->create(['name' => 'Шаги'])->entries()->create(['date' => '2026-11-02', 'value' => 20000]);
        $other->habits()->create(['name' => 'Чужая привычка'])->checks()->create(['date' => '2026-11-02']);

        $result = (new MonthReview($user, CarbonImmutable::parse('2026-11-10')))->data();
        $current = $result['current'];
        $this->assertEquals(['tasks' => 1, 'goals' => 1, 'checks' => 2, 'habitRate' => 67, 'steps' => 6000, 'sleep' => 25200, 'mood' => 4, 'energy' => 3, 'entries' => 2], $current['totals']);
        $this->assertSame(1, $result['previous']['goals']);
        $this->assertSame('2026-11-02', $current['bestDay']->toDateString());
        $this->assertSame(['name' => 'Зарядка', 'days' => 2], $current['streak']);
        $this->assertSame([1, 5, 1], array_column($current['days'], 'count'));

        $this->actingAs($user)->get('/cabinet/month-review')->assertOk()->assertSee('Зарядка')->assertDontSee('Чужая привычка');
        Livewire::test(MonthReviewPage::class)->call('shift', -1)->assertSet('month', '2026-10-01')
            ->call('shift', 1)->assertSet('month', '2026-11-01')
            ->call('shift', 1)->assertSet('month', '2026-11-01');
    }

    public function test_empty_state(): void
    {
        $this->actingAs(User::factory()->create())->get('/cabinet/month-review')
            ->assertOk()->assertSee('За этот месяц пока нет записей.');
    }
}
