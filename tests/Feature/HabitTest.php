<?php

namespace Tests\Feature;

use App\Filament\Cabinet\Widgets\HabitsToday;
use App\Models\User;
use App\Services\Coach;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class HabitTest extends TestCase
{
    use RefreshDatabase;

    public function test_streaks_week_and_toggle(): void
    {
        $user = User::factory()->create();
        $h = $user->habits()->create(['name' => 'Зарядка', 'days_per_week' => 5]);
        foreach (['2026-11-10', '2026-11-11', '2026-11-12', '2026-11-14'] as $d) {
            $h->checks()->create(['date' => $d]);
        }
        $today = CarbonImmutable::parse('2026-11-15'); // воскресенье

        $this->assertSame(1, $h->streak($today));
        $this->assertSame(3, $h->bestStreak());
        $this->assertSame(4, $h->thisWeek($today));
        $this->assertTrue($h->toggle('2026-11-13'));
        $this->assertSame(5, $h->fresh()->bestStreak());
        $this->assertFalse($h->toggle('2026-11-13'));
        $this->assertCount(30, $h->lastDays(30, $today));
    }

    public function test_widget_toggles_only_own_habits_and_coach_mentions_them(): void
    {
        $this->seed(\Database\Seeders\DatabaseSeeder::class);
        $user = User::factory()->create();
        $other = User::factory()->create()->habits()->create(['name' => 'Чужая']);
        $mine = $user->habits()->create(['name' => 'Читать']);
        $this->actingAs($user);
        Filament::setCurrentPanel('cabinet');

        Livewire::test(HabitsToday::class)->assertSee('Читать')->call('toggle', $mine->id);
        $this->assertTrue($mine->fresh()->isDoneOn(CarbonImmutable::today()));
        try {
            Livewire::test(HabitsToday::class)->call('toggle', $other->id);
        } catch (\Throwable) {
            // чужую привычку найти нельзя — это и проверяем ниже
        }
        $this->assertSame(0, $other->checks()->count());
    }

    public function test_public_page_shows_only_public_habits(): void
    {
        $this->seed(\Database\Seeders\DatabaseSeeder::class);
        $user = User::where('username', 'miras')->first();
        $user->habits()->create(['name' => 'Публичная привычка', 'is_public' => true]);
        $user->habits()->create(['name' => 'Секретная привычка']);

        $this->get('/u/miras')->assertOk()->assertSee('Публичная привычка')->assertDontSee('Секретная привычка');
        $this->actingAs($user)->get('/u/miras')->assertSee('Секретная привычка');

        $tips = array_column(Coach::for($user)->tips(10), 'title');
        $this->assertContains('Привычки на сегодня', $tips);
    }
}
