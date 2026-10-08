<?php

namespace App\Filament\Cabinet\Widgets;

use App\Services\UserStats;
use Filament\Widgets\ChartWidget;

class HorizonChart extends ChartWidget
{
    protected static ?int $sort = 4;

    protected ?string $heading = 'По горизонту планирования';

    protected ?string $maxHeight = '260px';

    protected function getData(): array
    {
        $rows = UserStats::for(auth()->user(), publicOnly: false)->byHorizon();

        return [
            'labels' => array_column($rows, 'label'),
            'datasets' => [
                ['label' => 'Всего', 'data' => array_column($rows, 'total'), 'backgroundColor' => '#94a3b8'],
                ['label' => 'Выполнено', 'data' => array_column($rows, 'done'), 'backgroundColor' => '#22c55e'],
            ],
        ];
    }

    protected function getType(): string
    {
        return 'bar';
    }
}
