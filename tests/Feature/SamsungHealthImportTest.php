<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\SamsungHealthImport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SamsungHealthImportTest extends TestCase
{
    use RefreshDatabase;

    /** Небольшие синтетические файлы в формате выгрузки Samsung Health. */
    private function files(): array
    {
        return [
            'steps.csv' => "\u{FEFF}com.samsung.shealth.step_daily_trend,7013001,6\n"
                ."binning_data,update_time,create_time,source_pkg_name,source_type,count,speed,distance,calorie,deviceuuid,pkg_name,datauuid,day_time\n"
                ."a.json,2026-03-13 05:56:46.176,2026-03-12 15:44:35.163,x,-2,1642,1.4,1253.4,62.79,d,x,a,2026-03-12 00:00:00.000,\n"
                ."b.json,2026-03-13 05:56:46.176,2026-03-12 15:44:35.163,x,0,999,1.4,800,62.79,d,x,b,2026-03-12 00:00:00.000,\n",
            'sleep.csv' => "com.samsung.shealth.sleep,7013001,12\n"
                ."sleep_duration,com.samsung.health.sleep.start_time,com.samsung.health.sleep.time_offset,com.samsung.health.sleep.end_time\n"
                ."365,2026-03-12 21:20:00.000,UTC+0500,2026-03-13 03:25:00.000,\n"
                ."15,2026-03-13 04:00:00.000,UTC+0500,2026-03-13 04:15:00.000,\n",
            'ex.csv' => "com.samsung.shealth.exercise,7013001,18\n"
                ."com.samsung.health.exercise.duration,com.samsung.health.exercise.start_time,com.samsung.health.exercise.exercise_type,com.samsung.health.exercise.distance,com.samsung.health.exercise.time_offset\n"
                ."1800000,2026-03-24 20:00:00.000,1002,5120.5,UTC+0500,\n",
            'hr.csv' => "com.samsung.shealth.tracker.heart_rate,7013001,3\n"
                ."com.samsung.health.heart_rate.start_time,com.samsung.health.heart_rate.time_offset,com.samsung.health.heart_rate.heart_rate\n"
                ."2026-03-12 11:00:29.324,UTC+0500,85.0,\n2026-03-12 15:00:00.000,UTC+0500,61.0,\n",
            'other.csv' => "com.samsung.shealth.stress,7013001,1\nscore\n10,\n",
        ];
    }

    public function test_parses_daily_series_in_local_time(): void
    {
        $s = (new SamsungHealthImport)->parse($this->files())->series();

        $this->assertSame(['2026-03-12' => 1642.0], $s['steps']);
        $this->assertSame(['2026-03-12' => 1.25], $s['distance']);
        $this->assertSame(['2026-03-13' => 380 * 60.0], $s['sleep']);      // ночной сон + дремота, на дату пробуждения
        $this->assertSame(['2026-03-25' => 1800.0], $s['exercise']);       // 20:00 UTC = 01:00 следующего дня по UTC+5
        $this->assertSame(['2026-03-25' => 5.12], $s['running']);
        $this->assertSame(['2026-03-12' => 61.0], $s['heart']);
        $this->assertArrayNotHasKey('weight', $s);
    }

    public function test_import_is_private_repeatable_and_keeps_manual_entries(): void
    {
        $this->seed(\Database\Seeders\DatabaseSeeder::class);
        $user = User::factory()->create();
        $sleep = $user->metrics()->create(['name' => 'Сон', 'is_duration' => true, 'is_public' => true]);
        $sleep->entries()->create(['date' => '2026-03-13', 'value' => 100, 'note' => 'вручную']);

        $result = (new SamsungHealthImport)->parse($this->files())->saveFor($user);
        (new SamsungHealthImport)->parse($this->files())->saveFor($user);

        $this->assertSame(0, $result['Сон']);
        $this->assertSame(100.0, $sleep->entries()->first()->value);
        $steps = $user->metrics()->where('name', 'Шаги')->first();
        $this->assertFalse($steps->is_public);
        $this->assertSame(1, $steps->entries()->count());
        $this->assertSame('Здоровье', $steps->category->name);
        $this->assertTrue($user->metrics()->where('name', 'Тренировки за день')->first()->is_duration);
    }

    public function test_cabinet_metrics_page_shows_import_button(): void
    {
        $this->seed(\Database\Seeders\DatabaseSeeder::class);
        $user = User::factory()->create();

        $this->actingAs($user)->get('/cabinet/metrics')->assertOk()->assertSee('Импорт Samsung Health');

        \Filament\Facades\Filament::setCurrentPanel('cabinet');
        $files = collect($this->files())->map(fn ($c, $n) => \Illuminate\Http\UploadedFile::fake()->createWithContent($n, $c))->values()->all();
        \Livewire\Livewire::test(\App\Filament\Cabinet\Resources\Metrics\Pages\ListMetrics::class)
            ->callAction('samsungHealth', ['files' => $files])
            ->assertHasNoActionErrors()
            ->assertNotified('Импорт готов');
        $this->assertSame(1642.0, $user->metrics()->where('name', 'Шаги')->first()->entries()->first()->value);
    }
}
