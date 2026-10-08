<?php

namespace App\Filament\Cabinet\Widgets;

use App\Services\UserStats;
use Filament\Widgets\ChartWidget;

class CategoryChart extends ChartWidget
{
    protected static ?int $sort = 3;

    protected ?string $heading = 'Цели по типам';

    protected ?string $maxHeight = '260px';

    protected function getData(): array
    {
        $rows = UserStats::for(auth()->user(), publicOnly: false)->byCategory();

        return [
            'labels' => array_column($rows, 'name'),
            'datasets' => [[
                'data' => array_column($rows, 'total'),
                'backgroundColor' => array_column($rows, 'color'),
            ]],
        ];
    }

    protected function getType(): string
    {
        return 'doughnut';
    }
}
