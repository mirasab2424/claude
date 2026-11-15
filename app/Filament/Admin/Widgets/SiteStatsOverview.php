<?php

namespace App\Filament\Admin\Widgets;

use App\Enums\GoalStatus;
use App\Models\DayLog;
use App\Models\Goal;
use App\Models\Habit;
use App\Models\HabitCheck;
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
            Stat::make('Привычек', Habit::count())->description(HabitCheck::where('date', '>=', now()->subDays(7)->toDateString())->count().' отметок за неделю'),
            Stat::make('Записей «как прошёл день»', DayLog::count())->description('содержимое видно только автору'),
        ];
    }
}
