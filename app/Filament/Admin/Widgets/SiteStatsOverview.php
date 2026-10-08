<?php

namespace App\Filament\Admin\Widgets;

use App\Enums\GoalStatus;
use App\Models\Goal;
use App\Models\MetricEntry;
use App\Models\Place;
use App\Models\User;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class SiteStatsOverview extends StatsOverviewWidget
{
    protected function getStats(): array
    {
        $newUsers = User::where('created_at', '>=', now()->subDays(30))->count();

        return [
            Stat::make('Пользователей', User::count())->description("+{$newUsers} за 30 дней"),
            Stat::make('Целей', Goal::count())
                ->description(Goal::where('status', GoalStatus::Done)->count().' выполнено'),
            Stat::make('Замеров метрик', MetricEntry::count()),
            Stat::make('Мест на карте', Place::count()),
        ];
    }
}
