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

    protected static ?string $title = 'Итоги недели';

    protected static ?string $slug = 'week';

    protected static ?int $navigationSort = 1;

    protected string $view = 'filament.cabinet.pages.week';

    public string $week = '';

    public function mount(): void
    {
        $this->week = CarbonImmutable::today()->startOfWeek()->toDateString();
    }

    public function shift(int $by): void
    {
        $next = CarbonImmutable::parse($this->week)->addWeeks($by);
        if ($next->lte(CarbonImmutable::today())) {
            $this->week = $next->toDateString();
        }
    }

    protected function getViewData(): array
    {
        return WeekReview::for(auth()->user(), CarbonImmutable::parse($this->week))->data();
    }
}
