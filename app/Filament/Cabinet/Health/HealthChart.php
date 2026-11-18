<?php

namespace App\Filament\Cabinet\Health;

use App\Services\HealthStats;
use Filament\Widgets\ChartWidget;

/** Базовый график здоровья: значения по дням + среднее за 7 дней. */
abstract class HealthChart extends ChartWidget
{
    protected static bool $isDiscovered = false;

    protected static bool $isLazy = false;

    protected ?string $maxHeight = '240px';

    protected int|string|array $columnSpan = 'full';

    public ?string $filter = '90';

    abstract protected function metric(): string;

    abstract protected function color(): string;

    protected function divider(): float
    {
        return 1;
    }

    protected function getFilters(): ?array
    {
        return ['30' => '30 дней', '90' => '90 дней', '365' => 'Год'];
    }

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getData(): array
    {
        $days = in_array($this->filter, ['30', '90', '365'], true) ? (int) $this->filter : 90;
        $s = HealthStats::for(auth()->user())->series($this->metric(), $days, $this->divider());

        return [
            'labels' => $s['labels'],
            'datasets' => [
                ['type' => 'line', 'label' => 'Среднее за 7 дней', 'data' => $s['avg'], 'borderColor' => '#151a1e', 'backgroundColor' => '#151a1e', 'pointRadius' => 0, 'borderWidth' => 2, 'tension' => 0.3, 'spanGaps' => true],
                ['label' => 'За день', 'data' => $s['vals'], 'backgroundColor' => $this->color(), 'borderRadius' => 3],
            ],
        ];
    }
}
