<?php

namespace Tests\Feature;

use App\Filament\Cabinet\Widgets\DayLogWidget;
use App\Models\User;
use App\Services\Coach;
use App\Services\HealthStats;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class DayLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_widget_saves_one_log_per_day(): void
    {
        $this->seed(\Database\Seeders\DatabaseSeeder::class);
        $user = User::factory()->create();
        $this->actingAs($user);
        Filament::setCurrentPanel('cabinet');

        Livewire::test(DayLogWidget::class)->call('setMood', 4)->call('setEnergy', 9)->set('note', 'Пробежал 5 км')->call('saveNote')->assertSee('Сохранено');

        $this->assertSame(1, $user->dayLogs()->count());
        $log = $user->dayLogs()->first();
        $this->assertSame([4, 5, 'Пробежал 5 км'], [$log->mood, $log->energy, $log->note]);
        $this->get('/u/'.$user->username)->assertDontSee('Пробежал 5 км');
    }

    public function test_mood_feeds_coach_and_insights(): void
    {
        $user = User::factory()->create();
        $sleep = $user->metrics()->create(['name' => HealthStats::SLEEP, 'is_duration' => true]);
        foreach (range(1, 6) as $i) {
            $d = CarbonImmutable::today()->subDays($i)->toDateString();
            $good = $i % 2 === 0;
            $sleep->entries()->create(['date' => $d, 'value' => ($good ? 8 : 5) * 3600]);
            $user->dayLogs()->create(['date' => $d, 'mood' => $i <= 3 ? 1 : ($good ? 5 : 2)]);
        }

        $this->assertContains('Тяжёлые дни', array_column(Coach::for($user)->tips(10), 'title'));
        $this->assertNotEmpty(array_filter(HealthStats::for($user)->insights(), fn ($l) => str_contains($l, 'Настроение после сна')));
    }
}
