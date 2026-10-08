<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum GoalStatus: string implements HasLabel, HasColor
{
    case Planned = 'planned';
    case InProgress = 'in_progress';
    case Done = 'done';
    case Failed = 'failed';
    case Dropped = 'dropped';

    public function getLabel(): string
    {
        return match ($this) {
            self::Planned => 'Запланировано',
            self::InProgress => 'В процессе',
            self::Done => 'Выполнено',
            self::Failed => 'Провалено',
            self::Dropped => 'Отменено',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Planned => 'gray',
            self::InProgress => 'info',
            self::Done => 'success',
            self::Failed => 'danger',
            self::Dropped => 'warning',
        };
    }

    public function isClosed(): bool
    {
        return in_array($this, [self::Done, self::Failed, self::Dropped], true);
    }
}
