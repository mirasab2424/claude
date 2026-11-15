<x-filament-panels::page>
    <style>@media (max-width: 640px) { .cal-steps { display: none; } }</style>
    <div style="display:flex; align-items:center; gap:8px; flex-wrap:wrap">
        <x-filament::button color="gray" size="sm" icon="heroicon-o-chevron-left" wire:click="shift(-1)" aria-label="Предыдущий месяц" />
        <h2 style="font-size:1.25rem; font-weight:700; margin:0 6px; text-transform:capitalize; min-width:170px; text-align:center">{{ $title }}</h2>
        <x-filament::button color="gray" size="sm" icon="heroicon-o-chevron-right" wire:click="shift(1)" aria-label="Следующий месяц" />
        <x-filament::button color="gray" size="sm" wire:click="today">Сегодня</x-filament::button>
        <span style="margin-left:auto; font-size:.8rem; opacity:.7">
            <span style="color:#22a845">●</span> выполнено &nbsp; <span style="color:#d97706">●</span> срок &nbsp; <span style="color:#dc2626">●</span> просрочено
            @if ($habitsTotal) &nbsp; ▮ привычки @endif &nbsp; 👣 шаги
        </span>
    </div>
    <div style="display:grid; grid-template-columns:repeat(7, minmax(0, 1fr)); gap:6px">
        @foreach ($weekdays as $w)
            <div style="font-size:.75rem; opacity:.6; text-align:center">{{ $w }}</div>
        @endforeach
        @foreach ($days as $day)
            <div style="min-height:104px; border-radius:10px; padding:6px; border:1px solid {{ $day['today'] ? '#22a845' : 'rgba(128,128,128,.2)' }}; opacity:{{ $day['in'] ? 1 : .45 }}; background:{{ $day['today'] ? 'rgba(34,168,69,.07)' : 'transparent' }}; overflow:hidden">
                <div style="display:flex; justify-content:space-between; font-size:.8rem">
                    <b>{{ $day['date']->day }}</b>
                    @if ($day['steps'])<span class="cal-steps" style="opacity:.6" title="Шаги">👣 {{ $day['steps'] >= 1000 ? round($day['steps'] / 1000, 1).'k' : $day['steps'] }}</span>@endif
                </div>
                @if ($habitsTotal && ! $day['date']->isFuture())
                    <div style="display:flex; gap:2px; margin:4px 0" title="Привычки: {{ $day['habits'] }} из {{ $habitsTotal }}">
                        @for ($i = 0; $i < $habitsTotal; $i++)
                            <i style="flex:1; height:4px; border-radius:2px; background:{{ $i < $day['habits'] ? '#22a845' : 'rgba(128,128,128,.18)' }}"></i>
                        @endfor
                    </div>
                @endif
                @foreach ($day['done']->take(3) as $g)
                    <a href="{{ $g['url'] }}" title="{{ $g['title'] }}" style="display:block; font-size:.72rem; line-height:1.3; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; color:#22a845">● {{ $g['title'] }}</a>
                @endforeach
                @foreach ($day['due']->take(2) as $g)
                    <a href="{{ $g['url'] }}" title="Срок: {{ $g['title'] }}" style="display:block; font-size:.72rem; line-height:1.3; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; color:{{ $g['overdue'] ? '#dc2626' : '#d97706' }}">● {{ $g['title'] }}</a>
                @endforeach
                @if ($day['done']->count() + $day['due']->count() > 5)
                    <span style="font-size:.7rem; opacity:.6">ещё {{ $day['done']->count() + $day['due']->count() - 5 }}</span>
                @endif
            </div>
        @endforeach
    </div>
</x-filament-panels::page>
