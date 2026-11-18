<x-filament-panels::page>
    <div style="display:flex; gap:8px; align-items:center; flex-wrap:wrap">
        <x-filament::button color="gray" size="sm" wire:click="shift(-1)" aria-label="Предыдущий день">‹ Вчера</x-filament::button>
        <h2 style="font-weight:700">{{ $date->translatedFormat('j F Y, l') }}</h2>
        <x-filament::button color="gray" size="sm" wire:click="shift(1)" aria-label="Следующий день">Завтра ›</x-filament::button>
        <x-filament::button size="sm" wire:click="today">Сегодня</x-filament::button>
    </div>

    <x-filament::section>
        <label for="day-progress" style="display:block; font-weight:600">Сделано {{ $done }} из {{ $total }}</label>
        <progress id="day-progress" value="{{ $done }}" max="{{ max(1, $total) }}" style="width:100%"></progress>
    </x-filament::section>

    <x-filament::section heading="Задачи">
        @forelse ($tasks as $task)
            <div wire:key="task-{{ $task->id }}" style="display:flex; justify-content:space-between; align-items:center; gap:8px; flex-wrap:wrap; margin-block:8px">
                <div style="overflow-wrap:anywhere">
                    {{ $task->title }}
                    <small>· {{ $task->due_date->translatedFormat('j M') }} · {{ $task->status->getLabel() }}</small>
                </div>
                <div style="display:flex; gap:6px; flex-wrap:wrap">
                    @if ($task->status === \App\Enums\GoalStatus::Done)
                        <x-filament::button color="gray" size="sm" wire:click="completeTask({{ $task->id }}, false)" wire:loading.attr="disabled">Вернуть</x-filament::button>
                    @elseif (! $task->status->isClosed())
                        <x-filament::button size="sm" wire:click="completeTask({{ $task->id }}, true)" wire:loading.attr="disabled">Выполнено</x-filament::button>
                        <x-filament::button color="gray" size="sm" wire:click="postponeTask({{ $task->id }})" wire:loading.attr="disabled">Срок +1 день</x-filament::button>
                    @endif
                </div>
            </div>
        @empty
            <p>Задач на этот день нет.</p>
        @endforelse
    </x-filament::section>

    <x-filament::section heading="Привычки">
        @forelse ($habits as $habit)
            @php($checked = $habit->checks->isNotEmpty())
            <div wire:key="habit-{{ $habit->id }}" style="display:flex; align-items:center; justify-content:space-between; gap:8px; margin-block:8px">
                <span style="overflow-wrap:anywhere">{{ $habit->icon }} {{ $habit->name }}</span>
                @unless ($date->isFuture())
                    <x-filament::button color="{{ $checked ? 'success' : 'gray' }}" size="sm"
                        wire:click="completeHabit({{ $habit->id }}, {{ $checked ? 'false' : 'true' }})" wire:loading.attr="disabled">
                        {{ $checked ? 'Снять отметку' : 'Отметить' }}
                    </x-filament::button>
                @endunless
            </div>
        @empty
            <p>Привычек на этот день нет.</p>
        @endforelse
    </x-filament::section>

    @if ($log)
        <x-filament::section heading="Как прошёл день">
            @if ($log->mood)<p>Настроение {{ \App\Models\DayLog::MOODS[$log->mood] ?? '' }} · {{ $log->mood }} из 5</p>@endif
            @if ($log->energy)<p>Энергия {{ $log->energy }} из 5</p>@endif
            @if ($log->note)<p style="white-space:pre-wrap; overflow-wrap:anywhere">{{ $log->note }}</p>@endif
        </x-filament::section>
    @endif
</x-filament-panels::page>
