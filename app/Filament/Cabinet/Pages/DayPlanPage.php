<?php

namespace App\Filament\Cabinet\Pages;

use App\Services\DayPlan;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Locked;

class DayPlanPage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static string|\UnitEnum|null $navigationGroup = 'Обзор';

    protected static ?string $title = 'План дня';

    protected static ?string $slug = 'day-plan';

    protected static ?int $navigationSort = 0;

    protected string $view = 'filament.cabinet.pages.day-plan';

    #[Locked]
    public string $day;

    public function mount(): void
    {
        $this->today();
    }

    public function today(): void
    {
        $this->day = CarbonImmutable::today()->toDateString();
    }

    public function shift(int $direction): void
    {
        abort_unless(in_array($direction, [-1, 1], true), 422);
        $this->day = CarbonImmutable::parse($this->day)->addDays($direction)->toDateString();
    }

    public function completeTask(int $id, bool $done): void
    {
        $this->plan()->completeTask($id, $done);
    }

    public function postponeTask(int $id): void
    {
        $this->plan()->postponeTask($id);
    }

    public function completeHabit(int $id, bool $done): void
    {
        $this->plan()->completeHabit($id, $done);
    }

    protected function getViewData(): array
    {
        return $this->plan()->data();
    }

    private function plan(): DayPlan
    {
        return new DayPlan(auth()->user(), CarbonImmutable::parse($this->day));
    }
}
