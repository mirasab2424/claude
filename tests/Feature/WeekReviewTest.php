<?php

namespace Tests\Feature;

use App\Enums\GoalStatus;
use App\Models\User;
use App\Services\WeekReview;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WeekReviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_week_summary_and_verdict(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-15 12:00'));
        $user = User::factory()->create();
        $user->goals()->create(['title' => 'Пробежка', 'status' => GoalStatus::Done, 'completed_at' => '2026-11-12 10:00']);
        $user->goals()->create(['title' => 'Старое', 'status' => GoalStatus::Done, 'completed_at' => '2026-11-02 10:00']);
        $h = $user->habits()->create(['name' => 'Зарядка', 'days_per_week' => 7]);
        foreach (range(9, 15) as $d) {
            $h->checks()->create(['date' => "2026-11-$d"]);
        }
        $user->dayLogs()->create(['date' => '2026-11-14', 'mood' => 4, 'note' => 'Хороший день']);

        $d = WeekReview::for($user, CarbonImmutable::parse('2026-11-11'))->data();

        $this->assertSame(['Пробежка'], $d['done']->pluck('title')->all());
        $this->assertSame(100, $d['habitRate']);
        $this->assertSame(4.0, (float) $d['mood']);
        $this->assertContains('выполнено целей: 1', $d['verdict']['good']);
        $this->assertSame('happy', $d['verdict']['mood']);
        $this->actingAs($user)->get('/cabinet/week')->assertOk();
    }
}
