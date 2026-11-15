<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\HealthStats;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HealthStatsTest extends TestCase
{
    use RefreshDatabase;

    private function seedDays(User $user, int $days): void
    {
        $steps = $user->metrics()->create(['name' => HealthStats::STEPS]);
        $sleep = $user->metrics()->create(['name' => HealthStats::SLEEP, 'is_duration' => true]);
        for ($i = 0; $i < $days; $i++) {
            $d = CarbonImmutable::parse('2026-09-01')->addDays($i)->toDateString();
            $good = $i % 2 === 0;
            $sleep->entries()->create(['date' => $d, 'value' => ($good ? 8 : 5) * 3600]);
            $steps->entries()->create(['date' => $d, 'value' => $good ? 9000 : 6000]);
        }
    }

    public function test_series_weekly_and_insights(): void
    {
        $user = User::factory()->create();
        $this->seedDays($user, 28);
        $h = HealthStats::for($user);

        $this->assertTrue($h->hasData());
        $this->assertSame('2026-09-28', $h->lastDate()->toDateString());
        $s = $h->series(HealthStats::SLEEP, 7, 3600);
        $this->assertCount(7, $s['vals']);
        $this->assertContains(8.0, $s['vals']);
        $this->assertNotEmpty($h->weekly());
        $this->assertStringContainsString('на 3 000 шагов больше', $h->insights()[0]);
    }

    public function test_page_shows_empty_state_and_data(): void
    {
        $this->seed(\Database\Seeders\DatabaseSeeder::class);
        $user = User::factory()->create();
        $this->actingAs($user)->get('/cabinet/health')->assertOk()->assertSee('Пока нет данных о здоровье');

        $this->seedDays($user, 28);
        $this->actingAs($user)->get('/cabinet/health')->assertOk()->assertSee('По неделям')->assertSee('Шаги по дням')->assertSee('Данные устарели');
    }
}
