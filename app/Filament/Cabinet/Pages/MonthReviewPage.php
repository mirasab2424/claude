<?php

namespace App\Filament\Cabinet\Pages;

use App\Services\MonthReview;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Locked;

class MonthReviewPage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static string|\UnitEnum|null $navigationGroup = 'Обзор';

    protected static ?string $title = 'Итоги месяца';

    protected static ?string $slug = 'month-review';

    protected static ?int $navigationSort = 2;

    protected string $view = 'filament.cabinet.pages.month-review';

    #[Locked]
    public string $month;

    public function mount(): void
    {
        $this->month = CarbonImmutable::today()->startOfMonth()->toDateString();
    }

    public function shift(int $direction): void
    {
        abort_unless(in_array($direction, [-1, 1], true), 422);
        $next = CarbonImmutable::parse($this->month)->addMonthsNoOverflow($direction);
        if ($next->lte(CarbonImmutable::today())) {
            $this->month = $next->toDateString();
        }
    }

    protected function getViewData(): array
    {
        return (new MonthReview(auth()->user(), CarbonImmutable::parse($this->month)))->data();
    }
}
