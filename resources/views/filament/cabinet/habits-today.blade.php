@php($today = now()->toImmutable())
<x-filament-widgets::widget>
    <x-filament::section heading="Привычки на сегодня" :description="$today->translatedFormat('l, j F')">
        @if ($habits->isEmpty())
            <p style="opacity:.75; margin:0">Добавьте 1–3 простые привычки — например «зарядка 10 минут» или «лечь до 23:00» — и отмечайте каждый день.</p>
            <p style="margin:10px 0 0"><x-filament::button tag="a" href="{{ \App\Filament\Cabinet\Resources\Habits\HabitResource::getUrl() }}" icon="heroicon-o-plus">Добавить привычку</x-filament::button></p>
        @else
            <div style="display:grid; grid-template-columns:repeat(auto-fill, minmax(260px, 1fr)); gap:10px">
                @foreach ($habits as $h)
                    @php($done = $h->isDoneOn($today))
                    <button type="button" wire:click="toggle({{ $h->id }})" wire:loading.attr="disabled"
                        style="text-align:left; cursor:pointer; border-radius:12px; padding:10px 12px; border:1px solid {{ $done ? '#22a845' : 'rgba(128,128,128,.25)' }}; background:{{ $done ? 'rgba(34,168,69,.12)' : 'transparent' }}; color:inherit; font:inherit">
                        <div style="display:flex; align-items:center; gap:10px">
                            <span style="font-size:22px; width:30px; height:30px; display:grid; place-items:center; border-radius:50%; background:{{ $done ? '#22a845' : 'rgba(128,128,128,.15)' }}; color:#fff">{{ $done ? '✓' : ($h->icon ?: '○') }}</span>
                            <span style="flex:1; min-width:0">
                                <b style="display:block">{{ $h->icon && $done ? $h->icon.' ' : '' }}{{ $h->name }}</b>
                                <small style="opacity:.65">серия {{ $h->streak() }} дн. · неделя {{ $h->thisWeek() }}/{{ $h->days_per_week }}</small>
                            </span>
                        </div>
                        <div style="display:flex; gap:4px; margin-top:8px" aria-hidden="true">
                            @foreach ($h->lastDays(14) as $d)
                                <i title="{{ $d['label'] }}" style="flex:1; height:6px; border-radius:3px; background:{{ $d['done'] ? '#22a845' : 'rgba(128,128,128,.2)' }}"></i>
                            @endforeach
                        </div>
                    </button>
                @endforeach
            </div>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
