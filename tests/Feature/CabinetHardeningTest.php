<?php

namespace Tests\Feature;

use App\Filament\Cabinet\Health\StepsChart;
use App\Filament\Cabinet\Pages\Calendar;
use App\Filament\Cabinet\Pages\Timeline;
use App\Filament\Cabinet\Pages\WeekReviewPage;
use App\Filament\Cabinet\Resources\Goals\Pages\CreateGoal;
use App\Filament\Cabinet\Resources\Habits\Pages\ManageHabits;
use App\Filament\Cabinet\Resources\Metrics\Pages\ListMetrics;
use App\Filament\Cabinet\Widgets\DayLogWidget;
use App\Filament\Cabinet\Widgets\HabitsToday;
use App\Models\User;
use App\Services\HealthStats;
use App\Services\SamsungHealthImport;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Tests\TestCase;
use ZipArchive;

class CabinetHardeningTest extends TestCase
{
    use RefreshDatabase;

    private function signIn(): User
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        Filament::setCurrentPanel('cabinet');

        return $user;
    }

    public function test_public_child_does_not_reveal_private_parent_and_owner_sees_own_parent(): void
    {
        $owner = User::factory()->create();
        $parent = $owner->goals()->create(['title' => 'Скрытая родительская цель', 'is_public' => false]);
        $owner->goals()->create(['title' => 'Видимая подзадача', 'is_public' => true, 'parent_id' => $parent->id]);

        $url = '/u/'.$owner->username;
        $this->get($url)->assertOk()->assertSee('Видимая подзадача')->assertDontSee('Скрытая родительская цель');
        $this->actingAs($owner)->get($url)->assertSee('Скрытая родительская цель');
    }

    public function test_goal_form_only_accepts_an_owned_parent(): void
    {
        $user = $this->signIn();
        $foreign = User::factory()->create()->goals()->create(['title' => 'Другая цель']);
        $parent = $user->goals()->create(['title' => 'Моя цель']);

        Livewire::test(CreateGoal::class)->fillForm(['title' => 'Подзадача', 'parent_id' => $foreign->id])
            ->call('create')->assertHasFormErrors(['parent_id']);
        Livewire::test(CreateGoal::class)->fillForm(['title' => 'Подзадача', 'parent_id' => $parent->id])
            ->call('create')->assertHasNoFormErrors();
        $this->assertDatabaseHas('goals', ['user_id' => $user->id, 'title' => 'Подзадача', 'parent_id' => $parent->id]);
    }

    public function test_archived_habits_are_not_mutable_from_today_widget(): void
    {
        $user = $this->signIn();
        $habit = $user->habits()->create(['name' => 'Старая привычка', 'archived_at' => now()]);
        Livewire::test(HabitsToday::class)->call('toggle', $habit->id)->assertNotFound();
        $this->assertSame(0, $habit->checks()->count());
    }

    public function test_archiving_is_applied_only_when_the_edit_form_is_saved(): void
    {
        $user = $this->signIn();
        $habit = $user->habits()->create(['name' => 'Привычка']);
        $page = Livewire::test(ManageHabits::class)->mountTableAction('edit', $habit)
            ->setTableActionData(['archived' => true]);
        $this->assertNull($habit->refresh()->archived_at);
        $page->callMountedTableAction()->assertHasNoTableActionErrors();
        $this->assertNotNull($habit->refresh()->archived_at);
    }

    public function test_day_log_updates_legacy_date_rows_and_preserves_a_zero_note(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-18 12:00'));
        $user = $this->signIn();
        DB::table('day_logs')->insert(['user_id' => $user->id, 'date' => '2026-11-18', 'mood' => 2]);

        Livewire::test(DayLogWidget::class)->set('note', '0')->call('saveNote')->call('setEnergy', 4);
        $this->assertSame(1, $user->dayLogs()->count());
        $log = $user->dayLogs()->firstOrFail();
        $this->assertSame([2, 4, '0'], [$log->mood, $log->energy, $log->note]);
    }

    public function test_calendar_and_week_accept_only_one_step_navigation(): void
    {
        $this->signIn();
        Livewire::test(Calendar::class)->call('shift', 2)->assertStatus(422);
        Livewire::test(WeekReviewPage::class)->call('shift', 2)->assertStatus(422);
    }

    public function test_timeline_window_is_controlled_by_pagination(): void
    {
        $this->signIn();
        $page = Livewire::test(Timeline::class)->call('more')->assertSet('days', 60);
        $this->expectException(CannotUpdateLockedPropertyException::class);
        $page->set('days', 90);
    }

    public function test_health_chart_uses_default_for_an_unknown_filter(): void
    {
        $this->signIn();
        $page = Livewire::test(StepsChart::class)->set('filter', '45');
        $method = new \ReflectionMethod(StepsChart::class, 'getData');
        $this->assertCount(90, $method->invoke($page->instance())['labels']);
    }

    public function test_manual_step_correction_survives_reimport_and_numeric_input_has_bounds(): void
    {
        $user = $this->signIn();
        $metric = $user->metrics()->create(['name' => HealthStats::STEPS, 'is_public' => false]);
        $entry = $metric->entries()->create(['date' => '2026-03-12', 'value' => 1000, 'note' => SamsungHealthImport::NOTE]);
        Livewire::test(ListMetrics::class)
            ->callTableAction('addEntry', $metric, ['date' => '2026-03-12', 'value' => '10000000000'])
            ->assertHasTableActionErrors(['value']);
        Livewire::test(ListMetrics::class)
            ->callTableAction('addEntry', $metric, ['date' => '2026-03-12', 'value' => '1500'])
            ->assertHasNoTableActionErrors();
        $result = (new SamsungHealthImport)->parse(['steps.csv' => $this->stepCsv("2026-03-12,-2,5000,3000\n")])->saveFor($user);
        $this->assertSame(0, $result[HealthStats::STEPS]);
        $this->assertNull($entry->refresh()->note);
        $this->assertSame(1500.0, $entry->value);
    }

    public function test_import_keeps_new_data_private_when_a_public_metric_has_the_same_name(): void
    {
        $user = User::factory()->create();
        $public = $user->metrics()->create(['name' => HealthStats::STEPS, 'is_public' => true]);
        $public->entries()->create(['date' => '2026-03-12', 'value' => 321.0]);
        $import = new SamsungHealthImport;
        $import->parse(['steps.csv' => $this->stepCsv("2026-03-12,-2,5000,3000\n2026-03-13,-2,4500,2000\n")]);
        $import->saveFor($user);
        $import->saveFor($user);

        $private = $user->metrics()->where('name', HealthStats::STEPS)->where('is_public', false)->sole();
        $this->assertSame(1, $public->entries()->count());
        $this->assertSame(1, $private->entries()->count());
        $this->assertSame([321.0, 4500.0], HealthStats::for($user)->series(HealthStats::STEPS, 2)['vals']);
        $this->get('/u/'.$user->username)->assertOk()->assertDontSee('4500');
    }

    public function test_malformed_csv_rows_and_invalid_zip_are_ignored(): void
    {
        $import = (new SamsungHealthImport)->parse([
            'bad.zip' => 'Файл не в формате ZIP',
            'steps.csv' => $this->stepCsv("2026-02-30,-2,1500,1000\n2026-03-11,-2,нет,нет\n2026-03-12,-2,2000,1250,\n2026-03-13,-2\n"),
        ]);
        $this->assertSame(['2026-03-12' => 2000.0], $import->series()['steps']);
        $this->assertSame(['2026-03-12' => 1.25], $import->series()['distance']);
    }

    public function test_valid_archive_can_contain_a_folder_and_unrelated_csv(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'samsung-test');
        try {
            $zip = new ZipArchive;
            $this->assertTrue($zip->open($path, ZipArchive::OVERWRITE));
            $zip->addFromString('Samsung Health/com.samsung.shealth.step_daily_trend.20261118.csv', $this->stepCsv("2026-03-12,-2,2000,1250\n"));
            $zip->addFromString('Samsung Health/other.csv', 'Другие данные');
            $zip->close();
            $result = (new SamsungHealthImport)->parse(['export.zip' => file_get_contents($path)])->series();
            $this->assertSame(['2026-03-12' => 2000.0], $result['steps']);
        } finally {
            unlink($path);
        }
    }

    public function test_heart_only_health_data_is_visible_to_its_owner(): void
    {
        $user = $this->signIn();
        $user->metrics()->create(['name' => HealthStats::HEART, 'is_public' => false])
            ->entries()->create(['date' => '2026-03-12', 'value' => 62]);
        $stats = HealthStats::for($user);
        $this->assertTrue($stats->hasData());
        $this->assertSame(62, $stats->weekly()[0]['heart']);
        $this->get('/cabinet/health')->assertOk();
    }

    private function stepCsv(string $rows): string
    {
        return "com.samsung.shealth.step_daily_trend,7013001,6\nday_time,source_type,count,distance\n".$rows;
    }
}
