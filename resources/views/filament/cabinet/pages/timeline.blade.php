<x-filament-panels::page>
    <div style="display:grid; gap:10px">
        @php($gap = 0)
        @foreach ($items as $it)
            @if ($it['empty'])
                @php($gap++)
                @continue
            @endif
            @if ($gap)
                <div style="font-size:.8rem; opacity:.45; padding-left:4px">· · · {{ $gap }} {{ $gap % 10 === 1 && $gap % 100 !== 11 ? 'день' : (in_array($gap % 10, [2, 3, 4]) && ! in_array($gap % 100, [12, 13, 14]) ? 'дня' : 'дней') }} без записей</div>
                @php($gap = 0)
            @endif
            <x-filament::section>
                <div style="display:flex; gap:14px; align-items:flex-start">
                    <div style="min-width:64px; text-align:center">
                        <div style="font-size:1.6rem; font-weight:700; line-height:1">{{ $it['date']->day }}</div>
                        <div style="font-size:.75rem; opacity:.6">{{ $it['date']->translatedFormat('M, D') }}</div>
                        @if ($it['log']?->mood)<div style="font-size:1.5rem; margin-top:4px" title="настроение {{ $it['log']->mood }} из 5">{{ \App\Models\DayLog::MOODS[$it['log']->mood] }}</div>@endif
                    </div>
                    <div style="flex:1; min-width:0; display:grid; gap:4px">
                        @if ($it['log']?->note)<div style="font-style:italic">«{{ $it['log']->note }}»</div>@endif
                        @foreach ($it['goals'] as $g)<div>✅ {{ $g->title }}</div>@endforeach
                        @if ($it['habits']->isNotEmpty())<div style="opacity:.85">🔁 {{ $it['habits']->implode(' · ') }}</div>@endif
                        @foreach ($it['entries'] as $e)<div style="opacity:.85">📈 {{ $e }}</div>@endforeach
                        @if ($it['steps'])<div style="opacity:.6; font-size:.85rem">👣 {{ number_format($it['steps'], 0, ',', ' ') }} шагов</div>@endif
                    </div>
                </div>
            </x-filament::section>
        @endforeach
        @if ($gap)
            <div style="font-size:.8rem; opacity:.45; padding-left:4px">· · · {{ $gap }} {{ $gap % 10 === 1 && $gap % 100 !== 11 ? 'день' : (in_array($gap % 10, [2, 3, 4]) && ! in_array($gap % 100, [12, 13, 14]) ? 'дня' : 'дней') }} без записей</div>
        @endif
        <div style="text-align:center"><x-filament::button color="gray" wire:click="more">Показать ещё 30 дней</x-filament::button></div>
    </div>
</x-filament-panels::page>
