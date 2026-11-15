<?php

namespace Tests\Feature;

use App\Enums\GoalStatus;
use App\Filament\Cabinet\Pages\Calendar;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class CalendarTest extends TestCase
{
    use RefreshDatabase;

    public function test_calendar_shows_own_goals_and_navigates(): void
    {
        $this->seed(\Database\Seeders\DatabaseSeeder::class);
        $user = User::factory()->create();
        $user->goals()->create(['title' => 'Срок в этом месяце', 'status' => GoalStatus::Planned, 'due_date' => now()->startOfMonth()->addDays(9)]);
        User::factory()->create()->goals()->create(['title' => 'Чужая цель', 'status' => GoalStatus::Planned, 'due_date' => now()->startOfMonth()->addDays(9)]);
        $this->actingAs($user);
        Filament::setCurrentPanel('cabinet');

        $this->get('/cabinet/calendar')->assertOk();
        Livewire::test(Calendar::class)
            ->assertSee('Срок в этом месяце')->assertDontSee('Чужая цель')
            ->call('shift', 1)->assertDontSee('Срок в этом месяце')
            ->call('today')->assertSee('Срок в этом месяце')
            ->set('month', 'abc')->assertOk()->assertSee('Срок в этом месяце');
    }
}
