@php
    $links = [
        ['🤖', 'Робот-тренер', 'советы ниже, по вашим записям', null],
        ['✅', 'Привычки', 'отметка одним нажатием, серии', \App\Filament\Cabinet\Resources\Habits\HabitResource::getUrl()],
        ['❤️', 'Здоровье', 'загрузите выгрузку Samsung Health в «Метриках»', \App\Filament\Cabinet\Pages\Health::getUrl()],
        ['📅', 'Календарь', 'сроки и выполненное по дням', \App\Filament\Cabinet\Pages\Calendar::getUrl()],
        ['📜', 'Лента дней', 'всё по дням', \App\Filament\Cabinet\Pages\Timeline::getUrl()],
        ['📋', 'Итоги недели', 'вывод и фокус от тренера', \App\Filament\Cabinet\Pages\WeekReviewPage::getUrl()],
    ];
@endphp
<x-filament-widgets::widget>
    <div x-data="{ hidden: (() => { try { return localStorage.getItem('whats-new-2026-11-15') === '1' } catch (e) { return false } })() }" x-show="! hidden" x-cloak>
        <x-filament::section>
            <div style="display:flex; justify-content:space-between; align-items:center; gap:8px">
                <h2 style="font-weight:700; margin:0">✨ Новое в кабинете</h2>
                <button type="button" aria-label="Скрыть" style="font-size:20px; opacity:.6; background:none; border:0; cursor:pointer"
                    x-on:click="hidden = true; try { localStorage.setItem('whats-new-2026-11-15', '1') } catch (e) {}">×</button>
            </div>
            <div style="display:grid; grid-template-columns:repeat(auto-fill, minmax(210px, 1fr)); gap:8px; margin-top:10px">
                @foreach ($links as [$icon, $name, $hint, $url])
                    @if ($url)
                        <a href="{{ $url }}" style="display:block; padding:8px 10px; border-radius:10px; border:1px solid rgba(128,128,128,.2)"><b>{{ $icon }} {{ $name }}</b><br><small style="opacity:.65">{{ $hint }}</small></a>
                    @else
                        <div style="padding:8px 10px; border-radius:10px; border:1px solid rgba(128,128,128,.2)"><b>{{ $icon }} {{ $name }}</b><br><small style="opacity:.65">{{ $hint }}</small></div>
                    @endif
                @endforeach
            </div>
        </x-filament::section>
    </div>
</x-filament-widgets::widget>
