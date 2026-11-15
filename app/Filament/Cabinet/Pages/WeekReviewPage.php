<?php

namespace App\Filament\Cabinet\Pages;

use App\Services\WeekReview;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

class WeekReviewPage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    protected static string|\UnitEnum|null $navigationGroup = 'Обзор';

    protected static ?string $title = 'Итоги недели';

    protected static ?string $slug = 'week';

    protected static ?int $navigationSort = 1;

    protected string $view = 'filament.cabinet.pages.week';

    public string $week = '';

    public function mount(): void
    {
        $this->week = CarbonImmutable::today()->startOfWeek()->toDateString();
    }

    /** Неделя приходит из браузера (Livewire) — только дата ГГГГ-ММ-ДД, иначе текущая. */
    private function safeWeek(): CarbonImmutable
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->week) || ! checkdate((int) substr($this->week, 5, 2), (int) substr($this->week, 8, 2), (int) substr($this->week, 0, 4))) {
            $this->week = CarbonImmutable::today()->startOfWeek()->toDateString();
        }

        return CarbonImmutable::parse($this->week);
    }

    public function shift(int $by): void
    {
        $next = $this->safeWeek()->addWeeks($by);
        if ($next->lte(CarbonImmutable::today())) {
            $this->week = $next->toDateString();
        }
    }

    protected function getViewData(): array
    {
        return WeekReview::for(auth()->user(), $this->safeWeek())->data();
    }
}
