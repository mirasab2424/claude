<?php

namespace App\Filament\Cabinet\Pages;

use App\Enums\GoalStatus;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

/** Лента дней: настроение и заметка, выполненные цели, привычки и замеры — всё по дням, свежие сверху. */
class Timeline extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedQueueList;

    protected static string|\UnitEnum|null $navigationGroup = 'Обзор';

    protected static ?string $title = 'Лента дней';

    protected static ?string $slug = 'timeline';

    protected static ?int $navigationSort = 1;

    protected string $view = 'filament.cabinet.pages.timeline';

    #[\Livewire\Attributes\Locked]
    public int $days = 30;

    public function more(): void
    {
        $this->days = min(365 * 5, $this->days + 30);
    }

    protected function getViewData(): array
    {
        $user = auth()->user();
        $to = CarbonImmutable::today();
        $from = $to->subDays($this->days - 1);
        $range = [$from->toDateString(), $to->toDateString().' 23:59:59'];
        $key = fn ($d) => CarbonImmutable::parse($d)->toDateString();
        // ежедневные ряды Samsung Health показываем отдельно (шаги) или не показываем — их слишком много
        $daily = ['Шаги', 'Пройдено за день', 'Сон', 'Пульс, минимум за день', 'Тренировки за день'];

        $logs = $user->dayLogs()->whereBetween('date', $range)->get()->keyBy(fn ($l) => $l->date->toDateString());
        $goals = $user->goals()->where('status', GoalStatus::Done)->whereBetween('completed_at', $range)->get()->groupBy(fn ($g) => $key($g->completed_at));
        $habitNames = $user->habits()->pluck('name', 'id');
        $checks = \App\Models\HabitCheck::whereIn('habit_id', $habitNames->keys())->whereBetween('date', $range)->get()->groupBy(fn ($c) => $key($c->date));
        $entries = \App\Models\MetricEntry::whereIn('metric_id', $user->metrics()->whereNotIn('name', $daily)->pluck('id'))->whereBetween('date', $range)->with('metric')->get()->groupBy(fn ($e) => $key($e->date));
        $steps = $user->metrics()->where('name', 'Шаги')->first()?->entries()->whereBetween('date', $range)->get()->mapWithKeys(fn ($e) => [$e->date->toDateString() => (int) $e->value]) ?? collect();

        $items = [];
        for ($d = $to; $d->gte($from); $d = $d->subDay()) {
            $k = $d->toDateString();
            $item = [
                'date' => $d,
                'log' => $logs[$k] ?? null,
                'goals' => $goals[$k] ?? collect(),
                'habits' => ($checks[$k] ?? collect())->map(fn ($c) => $habitNames[$c->habit_id] ?? null)->filter()->values(),
                'entries' => ($entries[$k] ?? collect())->map(fn ($e) => $e->metric->name.': '.$e->metric->format($e->value).($e->metric->unit && ! $e->metric->is_duration ? ' '.$e->metric->unit : '')),
                'steps' => $steps[$k] ?? null,
            ];
            $item['empty'] = ! $item['log'] && $item['goals']->isEmpty() && $item['habits']->isEmpty() && $item['entries']->isEmpty() && ! $item['steps'];
            $items[] = $item;
        }

        return ['items' => $items, 'days' => $this->days];
    }
}
