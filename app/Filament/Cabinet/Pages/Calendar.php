<?php

namespace App\Filament\Cabinet\Pages;

use App\Enums\GoalStatus;
use App\Filament\Cabinet\Resources\Goals\GoalResource;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

/** Календарь месяца: выполненные цели, сроки, отметки привычек и шаги по дням. */
class Calendar extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static string|\UnitEnum|null $navigationGroup = 'Обзор';

    protected static ?string $title = 'Календарь';

    protected static ?int $navigationSort = 1;

    protected string $view = 'filament.cabinet.pages.calendar';

    public string $month = '';

    public function mount(): void
    {
        $this->month = now()->format('Y-m');
    }

    /** Месяц приходит из браузера (Livewire) — принимаем только вид ГГГГ-ММ. */
    private function safeMonth(): string
    {
        return preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $this->month) ? $this->month : ($this->month = now()->format('Y-m'));
    }

    public function shift(int $by): void
    {
        $this->safeMonth();
        $this->month = CarbonImmutable::parse($this->month.'-01')->addMonths($by)->format('Y-m');
    }

    public function today(): void
    {
        $this->month = now()->format('Y-m');
    }

    protected function getViewData(): array
    {
        $user = auth()->user();
        $first = CarbonImmutable::parse($this->safeMonth().'-01');
        $from = $first->startOfWeek();
        $to = $first->endOfMonth()->endOfWeek();
        $goals = $user->goals()->where(fn ($q) => $q->whereBetween('completed_at', [$from, $to->endOfDay()])->orWhereBetween('due_date', [$from, $to]))->get();
        $habits = $user->habits()->active()->with('checks')->get();
        $steps = $user->metrics()->where('name', 'Шаги')->first()?->entries()->whereBetween('date', [$from, $to->endOfDay()])->get()
            ->mapWithKeys(fn ($e) => [$e->date->toDateString() => (int) $e->value]) ?? collect();

        $days = [];
        for ($d = $from; $d->lte($to); $d = $d->addDay()) {
            $key = $d->toDateString();
            $done = $goals->filter(fn ($g) => $g->status === GoalStatus::Done && $g->completed_at?->toDateString() === $key);
            $due = $goals->filter(fn ($g) => ! $g->status->isClosed() && $g->due_date?->toDateString() === $key);
            $days[] = [
                'date' => $d,
                'in' => $d->month === $first->month,
                'today' => $d->isToday(),
                'done' => $done->map(fn ($g) => ['title' => $g->title, 'url' => GoalResource::getUrl('edit', ['record' => $g])])->values(),
                'due' => $due->map(fn ($g) => ['title' => $g->title, 'url' => GoalResource::getUrl('edit', ['record' => $g]), 'overdue' => $d->lt(CarbonImmutable::today())])->values(),
                'habits' => $habits->filter(fn ($h) => $h->isDoneOn($d))->count(),
                'steps' => $steps[$key] ?? null,
            ];
        }

        return [
            'title' => $first->translatedFormat('F Y'),
            'days' => $days,
            'habitsTotal' => $habits->count(),
            'weekdays' => ['Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб', 'Вс'],
        ];
    }
}
