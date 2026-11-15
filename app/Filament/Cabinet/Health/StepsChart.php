<?php

namespace App\Filament\Cabinet\Health;

use App\Services\HealthStats;

class StepsChart extends HealthChart
{
    protected ?string $heading = 'Шаги по дням';

    protected function metric(): string
    {
        return HealthStats::STEPS;
    }

    protected function color(): string
    {
        return '#22a845';
    }

    protected function divider(): float
    {
        return 1;
    }
}
