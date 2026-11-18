<?php

namespace Tests\Feature;

use App\Enums\GoalStatus;
use App\Filament\Cabinet\Pages\DayPlanPage;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class DayPlanTest extends TestCase
{
    use RefreshDatabase;

    public function test_plan_lists_the_owners_due_and_overdue_items_and_moves_between_days(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-18 12:00'));
        $user = User::factory()->create();
        $user->goals()->create(['title' => 'Моя задача', 'due_date' => '2026-11-18']);
        $user->goals()->create(['title' => 'Моя просроченная задача', 'due_date' => '2026-11-17']);
        $user->goals()->create(['title' => 'Моя завтрашняя задача', 'due_date' => '2026-11-19']);
        $user->dayLogs()->create(['date' => '2026-11-18', 'note' => 'Моя запись']);
        User::factory()->create()->goals()->create(['title' => 'Чужая задача', 'due_date' => '2026-11-18']);

        $this->actingAs($user)->get('/cabinet/day-plan')->assertOk()
            ->assertSee('Моя задача')->assertSee('Моя просроченная задача')->assertSee('Моя запись')
            ->assertDontSee('Чужая задача')->assertDontSee('Моя завтрашняя задача');
        Livewire::test(DayPlanPage::class)->call('shift', 1)->assertSet('day', '2026-11-19')
            ->assertSee('Моя завтрашняя задача')->call('today')->assertSet('day', '2026-11-18');
    }

    public function test_actions_complete_reopen_and_postpone_tasks_and_mark_habits_idempotently(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-18 12:00'));
        $user = User::factory()->create();
        $task = $user->goals()->create(['title' => 'Задача', 'due_date' => '2026-11-18']);
        $habit = $user->habits()->create(['name' => 'Зарядка']);
        $page = Livewire::actingAs($user)->test(DayPlanPage::class)
            ->call('completeTask', $task->id, true)->assertSee('Сделано 1 из 2');
        $this->assertSame(GoalStatus::Done, $task->refresh()->status);
        $page->call('completeTask', $task->id, false)
            ->call('postponeTask', $task->id)
            ->call('completeHabit', $habit->id, true)
            ->call('completeHabit', $habit->id, true)->assertSee('Сделано 1 из 1');
        $this->assertSame('2026-11-19', $task->refresh()->due_date->toDateString());
        $this->assertNull($task->completed_at);
        $this->assertSame(1, $habit->checks()->count());
        $page->call('completeHabit', $habit->id, false)->assertSee('Сделано 0 из 1');
        $this->assertSame(0, $habit->checks()->count());
    }

    public function test_actions_reject_foreign_ids(): void
    {
        $today = CarbonImmutable::today();
        $owner = User::factory()->create();
        $task = $owner->goals()->create(['title' => 'Чужая задача', 'due_date' => $today]);
        $habit = $owner->habits()->create(['name' => 'Чужая привычка']);
        $user = User::factory()->create();

        Livewire::actingAs($user)->test(DayPlanPage::class)->call('completeTask', $task->id, true)->assertNotFound();
        Livewire::actingAs($user)->test(DayPlanPage::class)->call('postponeTask', $task->id)->assertNotFound();
        Livewire::actingAs($user)->test(DayPlanPage::class)->call('completeHabit', $habit->id, true)->assertNotFound();
        $this->assertSame(GoalStatus::Planned, $task->refresh()->status);
        $this->assertSame($today->toDateString(), $task->due_date->toDateString());
        $this->assertSame(0, $habit->checks()->count());
    }
}
