<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum MetricDirection: string implements HasLabel
{
    case Up = 'up';
    case Down = 'down';

    public function getLabel(): string
    {
        return match ($this) {
            self::Up => 'Больше — лучше',
            self::Down => 'Меньше — лучше',
        };
    }
}
