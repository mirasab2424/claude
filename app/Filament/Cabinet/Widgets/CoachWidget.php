<?php

namespace App\Filament\Cabinet\Widgets;

use App\Services\Coach;
use Filament\Widgets\Widget;

/** Робот-тренер на главной кабинета: советы по целям, метрикам и Samsung Health. */
class CoachWidget extends Widget
{
    protected static ?int $sort = -2;

    protected static bool $isLazy = false;

    protected string $view = 'filament.cabinet.coach';

    protected int|string|array $columnSpan = 'full';

    protected function getViewData(): array
    {
        $coach = Coach::for(auth()->user());
        $tips = $coach->tips();

        return [
            'tips' => $tips,
            'mood' => $coach->mood($tips),
            'asOf' => $coach->healthAsOf(),
        ];
    }
}
