<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum Horizon: string implements HasLabel
{
    case Year = 'year';
    case Month = 'month';
    case Week = 'week';
    case Day = 'day';

    public function getLabel(): string
    {
        return match ($this) {
            self::Year => 'На год',
            self::Month => 'На месяц',
            self::Week => 'На неделю',
            self::Day => 'На день',
        };
    }
}
