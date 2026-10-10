<?php

namespace App\Filament\Cabinet\Widgets;

use App\Services\UserStats;
use Filament\Widgets\ChartWidget;

class ProgressChart extends ChartWidget
{
    protected static ?int $sort = 2;

    protected ?string $heading = 'Прогресс по месяцам';

    protected int|string|array $columnSpan = 'full';

    protected ?string $maxHeight = '280px';

    protected function getData(): array
    {
        $t = UserStats::for(auth()->user(), publicOnly: false)->timeline();

        return [
            'labels' => $t['labels'],
            'datasets' => [
                ['label' => 'Выполнено', 'data' => $t['done'], 'backgroundColor' => '#22c55e', 'borderColor' => '#22c55e'],
                ['label' => 'Провалено', 'data' => $t['failed'], 'backgroundColor' => '#ef4444', 'borderColor' => '#ef4444'],
                ['label' => 'Поставлено', 'data' => $t['created'], 'backgroundColor' => '#94a3b8', 'borderColor' => '#94a3b8'],
            ],
        ];
    }

    protected function getType(): string
    {
        return 'bar';
    }
}
