<x-filament-panels::page>
    @if (! $has)
        <x-filament::section>
            <div style="display:flex; gap:16px; align-items:center; flex-wrap:wrap">
                @include('filament.cabinet.coach-robot', ['mood' => 'calm'])
                <div>
                    <p style="font-weight:600; margin:0 0 4px">Пока нет данных о здоровье</p>
                    <p style="opacity:.75; margin:0">Загрузите выгрузку Samsung Health: «Метрики» → «Импорт Samsung Health». Здесь появятся графики шагов, сна и пульса, недельная сводка и связи между ними.</p>
                    <p style="margin:10px 0 0"><x-filament::button tag="a" href="{{ \App\Filament\Cabinet\Resources\Metrics\MetricResource::getUrl() }}">Перейти к метрикам</x-filament::button></p>
                </div>
            </div>
        </x-filament::section>
    @else
        @php($age = (int) $last->diffInDays(now()->startOfDay()))
        @if ($age > 7)
            <div style="border-left:4px solid #d97706; background:rgba(217,119,6,.08); border-radius:10px; padding:10px 14px">
                <b>Данные устарели на {{ $age }} дн.</b> Последняя запись — {{ $last->translatedFormat('j F Y') }}.
                В Samsung Health: Настройки → Скачать личные данные, затем «Метрики» → «Импорт Samsung Health».
                <x-filament::link href="{{ \App\Filament\Cabinet\Resources\Metrics\MetricResource::getUrl() }}">Перейти к импорту</x-filament::link>
            </div>
        @else
            <p style="opacity:.6; margin:0">По данным Samsung Health, последняя запись — {{ $last->translatedFormat('j F Y') }}.</p>
        @endif
        @if ($insights)
            <x-filament::section heading="Что видно в ваших данных" icon="heroicon-o-light-bulb">
                <ul style="margin:0; padding-left:1.2rem; display:grid; gap:6px; list-style:disc">
                    @foreach ($insights as $line)
                        <li>{{ $line }}</li>
                    @endforeach
                </ul>
                <p style="font-size:.75rem; opacity:.5; margin:10px 0 0">Это совпадения в ваших записях, а не доказанная причина.</p>
            </x-filament::section>
        @endif
        <x-filament::section heading="По неделям">
            <div style="overflow-x:auto">
                <table style="width:100%; border-collapse:collapse; font-size:.9rem">
                    <thead><tr style="text-align:left; opacity:.6">
                        <th style="padding:6px 8px">Неделя</th><th style="padding:6px 8px">Шагов в день</th><th style="padding:6px 8px">Сон за ночь</th><th style="padding:6px 8px">Мин. пульс</th><th style="padding:6px 8px">Тренировки</th>
                    </tr></thead>
                    <tbody>
                    @foreach ($weeks as $w)
                        <tr style="border-top:1px solid rgba(128,128,128,.2)">
                            <td style="padding:6px 8px; white-space:nowrap">{{ $w['week'] }}</td>
                            <td style="padding:6px 8px">{{ $w['steps'] !== null ? number_format($w['steps'], 0, ',', ' ') : '—' }}</td>
                            <td style="padding:6px 8px">{{ $w['sleep'] !== null ? intdiv((int) round($w['sleep'] * 60), 60).' ч '.str_pad((string) ((int) round($w['sleep'] * 60) % 60), 2, '0', STR_PAD_LEFT).' мин' : '—' }}</td>
                            <td style="padding:6px 8px">{{ $w['heart'] ?? '—' }}</td>
                            <td style="padding:6px 8px">{{ $w['exercise'] ? $w['exercise'].' мин' : '—' }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </x-filament::section>
    @endif
</x-filament-panels::page>
