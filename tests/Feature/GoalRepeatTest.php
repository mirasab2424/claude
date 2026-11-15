<?php

namespace Tests\Feature;

use App\Enums\GoalStatus;
use App\Enums\Horizon;
use App\Filament\Cabinet\Resources\Goals\Pages\ListGoals;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class GoalRepeatTest extends TestCase
{
    use RefreshDatabase;

    public function test_repeat_creates_next_period_copy(): void
    {
        $this->seed(\Database\Seeders\DatabaseSeeder::class);
        $this->travelTo(now()->setDate(2026, 11, 15));
        $user = User::factory()->create();
        $goal = $user->goals()->create(['title' => 'Пробежать 15 км за неделю', 'horizon' => Horizon::Week, 'status' => GoalStatus::Done,
            'due_date' => '2026-11-15', 'completed_at' => '2026-11-14', 'retrospective' => 'Было тяжело', 'progress' => 100]);
        $this->actingAs($user);
        Filament::setCurrentPanel('cabinet');

        Livewire::test(ListGoals::class)->callTableAction('repeat', $goal)->assertHasNoTableActionErrors();

        $copy = $user->goals()->where('id', '!=', $goal->id)->first();
        $this->assertSame('Пробежать 15 км за неделю', $copy->title);
        $this->assertSame(GoalStatus::Planned, $copy->status);
        $this->assertSame('2026-11-22', $copy->due_date->toDateString());
        $this->assertNull($copy->retrospective);
        $this->assertSame(0, (int) $copy->progress);
    }

    public function test_template_fills_goal_form(): void
    {
        $this->seed(\Database\Seeders\DatabaseSeeder::class);
        $user = User::factory()->create();
        $this->actingAs($user);
        Filament::setCurrentPanel('cabinet');

        Livewire::test(\App\Filament\Cabinet\Resources\Goals\Pages\CreateGoal::class)
            ->fillForm(['template' => 'book'])
            ->assertSchemaStateSet(['title' => 'Прочитать книгу «…»', 'horizon' => \App\Enums\Horizon::Month])
            ->fillForm(['title' => 'Прочитать «Атомные привычки»'])
            ->call('create')
            ->assertHasNoFormErrors();

        $goal = $user->goals()->first();
        $this->assertSame('Прочитать «Атомные привычки»', $goal->title);
        $this->assertSame('Знание', $goal->category->name);
    }
}
