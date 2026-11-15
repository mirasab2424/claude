<?php

namespace App\Filament\Cabinet\Widgets;

use App\Models\Habit;
use Filament\Widgets\Widget;

/** Привычки на сегодня: отметить одним нажатием, видна неделя и серия. */
class HabitsToday extends Widget
{
    protected static ?int $sort = -1;

    protected static bool $isLazy = false;

    protected string $view = 'filament.cabinet.habits-today';

    protected int|string|array $columnSpan = 'full';

    public function toggle(int $id): void
    {
        auth()->user()->habits()->findOrFail($id)->toggle();
    }

    protected function getViewData(): array
    {
        return ['habits' => auth()->user()->habits()->active()->with('checks')->get()];
    }
}
