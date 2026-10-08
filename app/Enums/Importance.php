<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum Importance: string implements HasLabel, HasColor
{
    case Must = 'must';
    case Need = 'need';
    case Want = 'want';

    public function getLabel(): string
    {
        return match ($this) {
            self::Must => 'Необходимо',
            self::Need => 'Нужно',
            self::Want => 'Хочется',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Must => 'danger',
            self::Need => 'warning',
            self::Want => 'info',
        };
    }

    public function weight(): int
    {
        return match ($this) {
            self::Must => 3,
            self::Need => 2,
            self::Want => 1,
        };
    }
}
