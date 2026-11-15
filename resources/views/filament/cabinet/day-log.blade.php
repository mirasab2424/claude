<x-filament-widgets::widget>
    <x-filament::section heading="Как прошёл день?" description="Только для вас — на публичной странице этого нет">
        <div style="display:flex; flex-wrap:wrap; gap:18px 28px; align-items:flex-start">
            <div>
                <div style="font-size:.8rem; opacity:.6; margin-bottom:4px">Настроение</div>
                <div style="display:flex; gap:6px">
                    @foreach (\App\Models\DayLog::MOODS as $v => $emoji)
                        <button type="button" wire:click="setMood({{ $v }})" title="{{ $v }} из 5" aria-pressed="{{ $log?->mood === $v ? 'true' : 'false' }}"
                            style="font-size:26px; width:46px; height:46px; border-radius:12px; cursor:pointer; border:2px solid {{ $log?->mood === $v ? '#22a845' : 'transparent' }}; background:{{ $log?->mood === $v ? 'rgba(34,168,69,.12)' : 'rgba(128,128,128,.08)' }}; filter:{{ $log?->mood && $log->mood !== $v ? 'grayscale(.7) opacity(.6)' : 'none' }}">{{ $emoji }}</button>
                    @endforeach
                </div>
            </div>
            <div>
                <div style="font-size:.8rem; opacity:.6; margin-bottom:4px">Энергия</div>
                <div style="display:flex; gap:4px; flex-wrap:wrap">
                    @foreach (\App\Models\DayLog::ENERGY as $v => $label)
                        <button type="button" wire:click="setEnergy({{ $v }})" aria-pressed="{{ $log?->energy === $v ? 'true' : 'false' }}"
                            style="font:inherit; font-size:.85rem; padding:6px 10px; border-radius:999px; cursor:pointer; border:1px solid {{ $log?->energy === $v ? '#22a845' : 'rgba(128,128,128,.3)' }}; background:{{ $log?->energy === $v ? '#22a845' : 'transparent' }}; color:{{ $log?->energy === $v ? '#fff' : 'inherit' }}">{{ $label }}</button>
                    @endforeach
                </div>
            </div>
            <div style="flex:1 1 260px">
                <div style="font-size:.8rem; opacity:.6; margin-bottom:4px">Одна строка о дне — вопрос дня: {{ $prompt }}</div>
                <form wire:submit="saveNote" style="display:flex; gap:6px; flex-wrap:wrap">
                    <input wire:model="note" maxlength="500" placeholder="{{ $prompt }}" style="flex:1 1 180px; min-width:0; font:inherit; padding:8px 10px; border-radius:10px; border:1px solid rgba(128,128,128,.3); background:transparent; color:inherit">
                    <x-filament::button type="submit" size="sm">{{ $saved ? 'Сохранено ✓' : 'Сохранить' }}</x-filament::button>
                </form>
            </div>
            <div>
                <div style="font-size:.8rem; opacity:.6; margin-bottom:4px">Неделя</div>
                <div style="display:flex; gap:4px">
                    @foreach ($week as $d)
                        <div style="text-align:center; width:30px"><div style="font-size:18px; line-height:26px">{{ $d['mood'] ? \App\Models\DayLog::MOODS[$d['mood']] : '·' }}</div><div style="font-size:.7rem; opacity:.55">{{ $d['day'] }}</div></div>
                    @endforeach
                </div>
            </div>
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
