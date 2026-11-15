<?php

namespace Tests\Feature;

use App\Filament\Cabinet\Resources\Metrics\Pages\ListMetrics;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class MetricQuickEntryTest extends TestCase
{
    use RefreshDatabase;

    public function test_quick_entry_parses_duration_and_replaces_same_day(): void
    {
        $this->seed(\Database\Seeders\DatabaseSeeder::class);
        $user = User::factory()->create();
        $run = $user->metrics()->create(['name' => 'Бег 3 км', 'is_duration' => true]);
        $this->actingAs($user);
        Filament::setCurrentPanel('cabinet');

        Livewire::test(ListMetrics::class)
            ->callTableAction('addEntry', $run, ['date' => '2026-11-15', 'value' => '13:52'])
            ->assertHasNoTableActionErrors();
        Livewire::test(ListMetrics::class)
            ->callTableAction('addEntry', $run, ['date' => '2026-11-15', 'value' => '13:40', 'note' => 'ветер'])
            ->assertHasNoTableActionErrors();
        Livewire::test(ListMetrics::class)
            ->callTableAction('addEntry', $run, ['date' => '2026-11-16', 'value' => 'быстро'])
            ->assertHasTableActionErrors(['value']);

        $this->assertSame(1, $run->entries()->count());
        $this->assertSame(820.0, $run->entries()->first()->value);
        $this->assertSame('ветер', $run->entries()->first()->note);
    }

    public function test_export_csv_contains_only_own_metrics(): void
    {
        $this->seed(\Database\Seeders\DatabaseSeeder::class);
        $user = User::factory()->create();
        $other = User::factory()->create();
        $user->metrics()->create(['name' => 'Вес', 'unit' => 'кг'])->entries()->create(['date' => '2026-11-01', 'value' => 74.5]);
        $other->metrics()->create(['name' => 'Чужая'])->entries()->create(['date' => '2026-11-01', 'value' => 1]);
        $this->actingAs($user);
        Filament::setCurrentPanel('cabinet');

        Livewire::test(ListMetrics::class)->callAction('exportCsv')->assertFileDownloaded('metrics-'.now()->format('Y-m-d').'.csv');
    }
}
