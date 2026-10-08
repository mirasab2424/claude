<?php

namespace App\Filament\Cabinet\Widgets;

use App\Services\UserStats;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class MyStatsOverview extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    protected function getStats(): array
    {
        $stats = UserStats::for(auth()->user(), publicOnly: false);
        $s = $stats->summary();
        $timeline = $stats->timeline(8);

        return [
            Stat::make('Уровень', $s['level'])
                ->description("{$s['xp']} XP · {$s['level_progress']}% до следующего")
                ->color('success'),
            Stat::make('Выполнено', "{$s['done']} из {$s['total']}")
                ->description($s['success_rate'] !== null ? "Успешность {$s['success_rate']}%" : 'Пока нечего считать')
                ->chart($timeline['done'])
                ->color('success'),
            Stat::make('В работе', $s['in_progress'] + $s['planned'])
                ->description($s['overdue'] ? "Просрочено: {$s['overdue']}" : 'Просрочек нет')
                ->color($s['overdue'] ? 'danger' : 'gray'),
            Stat::make('Серия', $s['streak'].' дн.')
                ->description('Дней подряд с выполненными задачами')
                ->color($s['streak'] > 0 ? 'warning' : 'gray'),
        ];
    }
}
