<?php

namespace App\Filament\Cabinet\Health;

use App\Services\HealthStats;

class SleepChart extends HealthChart
{
    protected ?string $heading = 'Сон, часов';

    protected function metric(): string
    {
        return HealthStats::SLEEP;
    }

    protected function color(): string
    {
        return '#7c6fe0';
    }

    protected function divider(): float
    {
        return 3600;
    }
}
