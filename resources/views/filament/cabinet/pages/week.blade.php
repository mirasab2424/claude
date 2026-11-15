<x-filament-panels::page>
    <div style="display:flex; align-items:center; gap:8px; flex-wrap:wrap">
        <x-filament::button color="gray" size="sm" icon="heroicon-o-chevron-left" wire:click="shift(-1)" aria-label="Прошлая неделя" />
        <h2 style="font-size:1.15rem; font-weight:700; margin:0 6px">{{ $from->translatedFormat('j M') }} – {{ $to->translatedFormat('j M Y') }}</h2>
        @unless ($isCurrent)<x-filament::button color="gray" size="sm" icon="heroicon-o-chevron-right" wire:click="shift(1)" aria-label="Следующая неделя" />@endunless
        @if ($isCurrent)<span style="opacity:.6; font-size:.85rem">текущая неделя, прошло дней: {{ $daysPassed }}</span>@endif
    </div>

    <x-filament::section>
        <div style="display:flex; gap:18px; align-items:flex-start; flex-wrap:wrap">
            @include('filament.cabinet.coach-robot', ['mood' => $verdict['mood']])
            <div style="flex:1 1 300px">
                <h3 style="font-weight:700; margin:0 0 6px">Вывод тренера</h3>
                @if ($verdict['good'])<p style="margin:0 0 6px">👍 <b>Получилось:</b> {{ implode(', ', $verdict['good']) }}.</p>@endif
                @if ($verdict['focus'])
                    <p style="margin:0 0 4px">🎯 <b>Фокус на следующую неделю:</b></p>
                    <ul style="margin:0; padding-left:1.3rem; list-style:disc">@foreach ($verdict['focus'] as $f)<li>{{ $f }}</li>@endforeach</ul>
                @endif
            </div>
        </div>
    </x-filament::section>

    <div style="display:grid; grid-template-columns:repeat(auto-fill, minmax(160px, 1fr)); gap:12px">
        @foreach ([
            ['Выполнено целей', $done->count(), $failed->count() ? 'провалено: '.$failed->count() : null],
            ['Привычки', $habitRate !== null ? $habitRate.' %' : '—', 'от плана'],
            ['Настроение', $mood !== null ? \App\Models\DayLog::MOODS[(int) round($mood)].' '.number_format($mood, 1, ',', '') : '—', $energy !== null ? 'энергия '.number_format($energy, 1, ',', '').' из 5' : 'нет записей'],
            ['Шаги в день', $steps !== null ? number_format($steps, 0, ',', ' ') : '—', 'Samsung Health'],
            ['Сон за ночь', $sleep !== null ? number_format($sleep, 1, ',', '').' ч' : '—', 'Samsung Health'],
        ] as [$label, $value, $hint])
            <x-filament::section>
                <div style="font-size:.8rem; opacity:.6">{{ $label }}</div>
                <div style="font-size:1.6rem; font-weight:700">{{ $value }}</div>
                @if ($hint)<div style="font-size:.8rem; opacity:.6">{{ $hint }}</div>@endif
            </x-filament::section>
        @endforeach
    </div>

    <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(300px, 1fr)); gap:12px">
        <x-filament::section heading="Выполнено">
            @forelse ($done as $g)
                <div style="padding:4px 0; border-bottom:1px solid rgba(128,128,128,.15)">✅ {{ $g->title }} <span style="opacity:.55; font-size:.8rem">· {{ $g->completed_at->translatedFormat('D, j M') }}</span></div>
            @empty
                <p style="opacity:.65; margin:0">На этой неделе выполненных целей нет.</p>
            @endforelse
        </x-filament::section>
        <x-filament::section heading="Привычки">
            @forelse ($habits as $h)
                <div style="display:flex; justify-content:space-between; gap:8px; padding:4px 0">
                    <span>{{ $h['name'] }}</span><b style="color:{{ $h['done'] >= $h['plan'] ? '#22a845' : 'inherit' }}">{{ $h['done'] }} / {{ $h['plan'] }}</b>
                </div>
            @empty
                <p style="opacity:.65; margin:0">Привычек пока нет.</p>
            @endforelse
        </x-filament::section>
        <x-filament::section heading="Дальше">
            @if ($overdue->count())
                <p style="margin:0 0 4px; color:#dc2626">Просрочено: {{ $overdue->pluck('title')->take(3)->implode(', ') }}</p>
            @endif
            @forelse ($nextWeek as $g)
                <div style="padding:3px 0">📌 {{ $g->title }} <span style="opacity:.55; font-size:.8rem">· до {{ $g->due_date->translatedFormat('j M') }}</span></div>
            @empty
                <p style="opacity:.65; margin:0 0 8px">На следующую неделю сроков нет.</p>
            @endforelse
            <x-filament::button tag="a" size="sm" icon="heroicon-o-plus" href="{{ \App\Filament\Cabinet\Resources\Goals\GoalResource::getUrl('create') }}" style="margin-top:8px">Цель на неделю</x-filament::button>
        </x-filament::section>
    </div>

    @if ($notes->isNotEmpty())
        <x-filament::section heading="Дневник недели">
            @foreach ($notes as $n)
                <div style="padding:4px 0">{{ $n->mood ? \App\Models\DayLog::MOODS[$n->mood] : '·' }} <b>{{ $n->date->translatedFormat('D, j M') }}</b> — {{ $n->note }}</div>
            @endforeach
        </x-filament::section>
    @endif
</x-filament-panels::page>
