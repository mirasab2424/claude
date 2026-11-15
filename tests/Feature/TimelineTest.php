<?php

namespace Tests\Feature;

use App\Enums\GoalStatus;
use App\Filament\Cabinet\Pages\Timeline;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class TimelineTest extends TestCase
{
    use RefreshDatabase;

    public function test_timeline_groups_own_records_by_day(): void
    {
        $this->seed(\Database\Seeders\DatabaseSeeder::class);
        $user = User::factory()->create();
        $user->goals()->create(['title' => 'Пробежка 5 км', 'status' => GoalStatus::Done, 'completed_at' => now()->subDays(2)]);
        $user->dayLogs()->create(['date' => now()->subDays(2)->toDateString(), 'mood' => 5, 'note' => 'Лучший день недели']);
        $user->metrics()->create(['name' => 'Вес', 'unit' => 'кг'])->entries()->create(['date' => now()->subDays(40)->toDateString(), 'value' => 74]);
        User::factory()->create()->goals()->create(['title' => 'Чужая', 'status' => GoalStatus::Done, 'completed_at' => now()->subDay()]);
        $this->actingAs($user);
        Filament::setCurrentPanel('cabinet');

        $this->get('/cabinet/timeline')->assertOk();
        Livewire::test(Timeline::class)
            ->assertSee('Пробежка 5 км')->assertSee('Лучший день недели')->assertDontSee('Чужая')->assertDontSee('Вес: 74')
            ->call('more')->assertSee('Вес: 74 кг');
    }
}
