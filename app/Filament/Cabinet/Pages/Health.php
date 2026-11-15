<?php

namespace App\Filament\Cabinet\Pages;

use App\Filament\Cabinet\Health\HeartChart;
use App\Filament\Cabinet\Health\SleepChart;
use App\Filament\Cabinet\Health\StepsChart;
use App\Services\HealthStats;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

/** Здоровье: графики и сводка по данным Samsung Health. Видит только владелец. */
class Health extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedHeart;

    protected static string|\UnitEnum|null $navigationGroup = 'Обзор';

    protected static ?string $title = 'Здоровье';

    protected static ?int $navigationSort = 3;

    protected string $view = 'filament.cabinet.pages.health';

    protected function getViewData(): array
    {
        $h = HealthStats::for(auth()->user());

        return ['has' => $h->hasData(), 'last' => $h->lastDate(), 'weeks' => $h->weekly(), 'insights' => $h->insights()];
    }

    protected function getHeaderWidgets(): array
    {
        return HealthStats::for(auth()->user())->hasData() ? [StepsChart::class, SleepChart::class, HeartChart::class] : [];
    }

    public function getHeaderWidgetsColumns(): int|array
    {
        return 1;
    }
}
