@php
    $tone = ['good' => ['#22a845', 'rgba(34,168,69,.08)'], 'warn' => ['#e8590c', 'rgba(232,89,12,.08)'], 'info' => ['#5b6168', 'rgba(91,97,104,.08)']];
    $greeting = match ($mood) { 'happy' => 'Отличная работа!', 'worried' => 'Есть над чем поработать', default => 'Вот что я вижу' };
@endphp
<x-filament-widgets::widget>
    <x-filament::section>
        <div style="display:flex; gap:20px; align-items:flex-start; flex-wrap:wrap">
            <div style="flex:0 0 120px; text-align:center">
                @include('filament.cabinet.coach-robot', ['mood' => $mood])
            </div>
            <div style="flex:1 1 320px; min-width:0">
                <div style="display:flex; align-items:baseline; gap:10px; flex-wrap:wrap">
                    <h2 style="font-size:1.15rem; font-weight:700; margin:0">Тренер: {{ $greeting }}</h2>
                    @if ($asOf)
                        <span style="font-size:.8rem; opacity:.6">здоровье — по данным на {{ $asOf->translatedFormat('j F') }}</span>
                    @endif
                </div>
                <div style="display:grid; grid-template-columns:repeat(auto-fill, minmax(240px, 1fr)); gap:10px; margin-top:12px">
                    @forelse ($tips as $tip)
                        <div style="border-left:3px solid {{ $tone[$tip['tone']][0] }}; background:{{ $tone[$tip['tone']][1] }}; border-radius:10px; padding:10px 12px">
                            <div style="font-weight:600">{{ $tip['icon'] }} {{ $tip['title'] }}</div>
                            <div style="font-size:.9rem; opacity:.85; margin-top:2px">{{ $tip['text'] }}</div>
                            @isset($tip['link'])
                                <a href="{{ $tip['link'][1] }}" style="display:inline-block; margin-top:6px; font-size:.85rem; font-weight:600; color:{{ $tone[$tip['tone']][0] }}">{{ $tip['link'][0] }} →</a>
                            @endisset
                        </div>
                    @empty
                        <div style="opacity:.7">Пока мало данных. Добавьте цель или метрику — и я начну подсказывать.</div>
                    @endforelse
                </div>
                <div style="font-size:.75rem; opacity:.5; margin-top:10px">Советы по правилам из ваших записей, это не медицинская рекомендация.</div>
            </div>
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
