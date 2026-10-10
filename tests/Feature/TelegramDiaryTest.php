<?php

namespace Tests\Feature;

use App\Models\Metric;
use App\Models\User;
use App\Services\UserStats;
use Database\Seeders\TelegramDiarySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class TelegramDiaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_import_replaces_user_data_with_diary(): void
    {
        Storage::fake('public');
        $this->seed(\Database\Seeders\DatabaseSeeder::class);
        $user = User::where('username', 'miras')->first();
        $user->goals()->create(['title' => 'Старая демо-цель']);

        (new TelegramDiarySeeder)->run($user);

        $this->assertSame(0, $user->goals()->where('title', 'Старая демо-цель')->count());
        $this->assertSame(88, $user->goals()->count());
        $this->assertSame('Just me', $user->fresh()->tagline);
        $this->assertSame(17, $user->metrics()->where('name', 'Вес')->first()->entries()->count());
        Storage::disk('public')->assertExists('goals/telegram-p76_2.jpg');

        $run = $user->metrics()->where('name', 'Бег 3 км')->first();
        $this->assertTrue($run->is_duration);
        $this->assertSame('13:53', $run->format($run->entries()->first()->value));

        $heatmap = UserStats::for($user)->heatmap();
        $this->assertArrayHasKey('2022', $heatmap);
        $this->assertSame(1, $heatmap['2023']['2023-07-28']);
    }

    public function test_duration_parsing_and_formatting(): void
    {
        $this->assertSame(832.0, Metric::parseDuration('13:52'));
        $this->assertSame(75.5, Metric::parseDuration('75,5'));
        $metric = new Metric(['is_duration' => true]);
        $this->assertSame('1:02:03', $metric->format(3723));
        $this->assertSame('−0:41', $metric->format(-41, signed: true));
        $this->assertSame('+2', (new Metric)->format(2, signed: true));
    }
}
