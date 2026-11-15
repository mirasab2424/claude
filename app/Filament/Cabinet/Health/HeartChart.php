<?php

namespace App\Filament\Cabinet\Health;

use App\Services\HealthStats;

class HeartChart extends HealthChart
{
    protected ?string $heading = 'Минимальный пульс, уд/мин';

    protected function metric(): string
    {
        return HealthStats::HEART;
    }

    protected function color(): string
    {
        return '#e66767';
    }

    protected function divider(): float
    {
        return 1;
    }

    protected function getOptions(): array
    {
        return ['scales' => ['y' => ['beginAtZero' => false, 'suggestedMin' => 40]]];
    }
}
