<?php

namespace App\Filament\Cabinet\Widgets;

use App\Models\DayLog;
use Carbon\CarbonImmutable;
use Filament\Widgets\Widget;

/** «Как прошёл день»: настроение и энергия одним нажатием, заметка. Видит только владелец. */
class DayLogWidget extends Widget
{
    protected static ?int $sort = 0;

    protected static bool $isLazy = false;

    protected string $view = 'filament.cabinet.day-log';

    protected int|string|array $columnSpan = 'full';

    public string $note = '';

    public bool $saved = false;

    public function mount(): void
    {
        $this->note = (string) $this->log()?->note;
    }

    private function log(): ?DayLog
    {
        return auth()->user()->dayLogs()->whereDate('date', CarbonImmutable::today())->first();
    }

    private function upsert(array $data): void
    {
        $log = $this->log();
        $log ? $log->update($data) : auth()->user()->dayLogs()->create(['date' => CarbonImmutable::today()->toDateString()] + $data);
    }

    public function setMood(int $v): void
    {
        $this->upsert(['mood' => max(1, min(5, $v))]);
    }

    public function setEnergy(int $v): void
    {
        $this->upsert(['energy' => max(1, min(5, $v))]);
    }

    public function saveNote(): void
    {
        $this->validate(['note' => 'nullable|string|max:500']);
        $this->upsert(['note' => trim($this->note) ?: null]);
        $this->saved = true;
    }

    protected function getViewData(): array
    {
        $week = auth()->user()->dayLogs()->where('date', '>=', CarbonImmutable::today()->subDays(6)->toDateString())->get()
            ->keyBy(fn ($l) => $l->date->toDateString());

        return [
            'log' => $this->log(),
            'week' => collect(range(6, 0))->map(fn ($i) => CarbonImmutable::today()->subDays($i))
                ->map(fn ($d) => ['day' => $d->translatedFormat('D'), 'mood' => $week[$d->toDateString()]->mood ?? null]),
        ];
    }
}
