<x-filament-panels::page>
    <div style="display:flex; gap:8px; align-items:center; flex-wrap:wrap">
        <x-filament::button color="gray" size="sm" wire:click="shift(-1)" aria-label="Предыдущий месяц">‹</x-filament::button>
        <h2 style="font-size:1.15rem; font-weight:700">{{ $reviewMonth->translatedFormat('F Y') }}</h2>
        @unless ($reviewMonth->isCurrentMonth())
            <x-filament::button color="gray" size="sm" wire:click="shift(1)" aria-label="Следующий месяц">›</x-filament::button>
        @endunless
    </div>

    @if ($current['bestDay'] === null)
        <x-filament::section>
            <p>За этот месяц пока нет записей.</p>
        </x-filament::section>
    @else
        @php
            $cards = [
                'tasks' => ['Выполнено задач', 0, ''],
                'goals' => ['Достигнуто целей', 0, ''],
                'checks' => ['Отметки привычек', 0, ''],
                'habitRate' => ['План привычек', 0, ' %'],
                'steps' => ['Средние шаги', 0, ''],
                'sleep' => ['Средний сон', 1, ' ч'],
                'mood' => ['Настроение', 1, ' из 5'],
                'energy' => ['Энергия', 1, ' из 5'],
                'entries' => ['Записи метрик', 0, ''],
            ];
        @endphp
        <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(min(100%, 160px), 1fr)); gap:12px">
            @foreach ($cards as $key => [$label, $precision, $unit])
                @php
                    $value = $current['totals'][$key];
                    $before = $previous[$key];
                    $scale = $key === 'sleep' ? 3600 : 1;
                    $difference = $value !== null && $before !== null ? round(($value - $before) / $scale, $precision) : null;
                @endphp
                <x-filament::section>
                    <p>{{ $label }}</p>
                    <p style="font-size:1.4rem; font-weight:700">{{ $value === null ? '—' : number_format($value / $scale, $precision, ',', ' ').$unit }}</p>
                    @if ($difference !== null)
                        <p style="font-size:.83rem">{{ $difference > 0 ? '↑ +' : ($difference < 0 ? '↓ −' : '→ ') }}{{ number_format(abs($difference), $precision, ',', ' ') }}{{ $key === 'habitRate' ? ' п. п.' : ($key === 'sleep' ? ' ч' : '') }} к прошлому месяцу</p>
                    @endif
                </x-filament::section>
            @endforeach
        </div>

        <x-filament::section heading="Активность по дням">
            <div style="display:grid; grid-template-columns:repeat(7, minmax(0, 1fr)); gap:4px; max-width:420px">
                @foreach (['Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб', 'Вс'] as $weekday)
                    <small style="text-align:center">{{ $weekday }}</small>
                @endforeach
                @for ($offset = 1; $offset < $reviewMonth->isoWeekday(); $offset++)
                    <span aria-hidden="true"></span>
                @endfor
                @foreach ($current['days'] as $day)
                    <div aria-label="{{ $day['date']->translatedFormat('j F') }}, записей {{ $day['count'] }}"
                        title="{{ $day['date']->translatedFormat('j F') }} · {{ $day['count'] }}"
                        style="text-align:center; padding-block:6px; border-radius:4px; background:rgba(16,185,129,{{ min(.65, $day['count'] * .14) }}); box-shadow:inset 0 0 0 1px rgba(128,128,128,.22)">
                        {{ $day['date']->day }}
                    </div>
                @endforeach
            </div>
            <p style="margin-top:12px">Лучший день — {{ $current['bestDay']->translatedFormat('j F') }}.</p>
            @if ($current['streak'])
                <p>Самая длинная серия привычки «{{ $current['streak']['name'] }}» — {{ $current['streak']['days'] }} дн. подряд.</p>
            @endif
        </x-filament::section>
    @endif
</x-filament-panels::page>
