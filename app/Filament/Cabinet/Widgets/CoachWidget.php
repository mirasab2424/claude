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

        // к каждому совету — куда перейти, чтобы действовать
        $links = [
            '/^(Мало сна|Хороший сон|Пульс|Сердце|Мало движения|Отличный день|Шаги)/u' => ['Здоровье', \App\Filament\Cabinet\Pages\Health::getUrl()],
            '/^(Просрочено|Сейчас в работе|Нет активной цели)/u' => ['Цели', \App\Filament\Cabinet\Resources\Goals\GoalResource::getUrl()],
            '/^(Привычк|Все привычки)/u' => ['Привычки', \App\Filament\Cabinet\Resources\Habits\HabitResource::getUrl()],
            '/^(Личный рекорд|Давно не замеряли|До цели по метрике|Цель по метрике)/u' => ['Метрики', \App\Filament\Cabinet\Resources\Metrics\MetricResource::getUrl()],
            '/^(Тяжёлые дни|Хорошая полоса)/u' => ['Лента дней', \App\Filament\Cabinet\Pages\Timeline::getUrl()],
        ];
        foreach ($tips as &$tip) {
            foreach ($links as $re => $link) {
                if (preg_match($re, $tip['title'])) {
                    $tip['link'] = $link;
                    break;
                }
            }
        }
        unset($tip);

        return [
            'tips' => $tips,
            'mood' => $coach->mood($tips),
            'asOf' => $coach->healthAsOf(),
        ];
    }
}
