@extends('layouts.site')

@section('title', $user->name)
@section('description', $user->tagline ?: 'Цели, прогресс и статистика — '.$user->name)

@push('head')
    <link rel="stylesheet" href="{{ asset('vendor/leaflet/leaflet.css') }}">
@endpush

@section('content')
<section class="profile-head">
    <div class="container profile-head__inner">
        <x-avatar :user="$user" size="96" />
        <div class="profile-head__info">
            <h1 class="profile-head__name">{{ $user->name }}</h1>
            @if ($user->tagline)<p class="profile-head__tagline">{{ $user->tagline }}</p>@endif
            <p class="muted">
                @if ($user->city){{ $user->city }} · @endif
                ведёт дневник с {{ ($since ?? $user->created_at)->translatedFormat('F Y') }}
                @if ($user->telegram) · <a href="{{ $user->telegram }}" target="_blank" rel="noopener">канал в Telegram ↗</a>@endif
                @if ($isOwner) · <a href="/cabinet">редактировать в кабинете</a>@endif
            </p>
        </div>
        <div class="level" aria-label="Уровень {{ $summary['level'] }}">
            <div class="level__badge">
                <span class="level__num">{{ $summary['level'] }}</span>
                <span class="level__caption">уровень</span>
            </div>
            <div class="level__xp">
                <div class="bar"><span style="width: {{ $summary['level_progress'] }}%"></span></div>
                <span class="muted">{{ $summary['xp'] }} XP · {{ $summary['level_progress'] }}% до {{ $summary['level'] + 1 }}-го</span>
            </div>
        </div>
    </div>
    @if ($user->bio)
        <div class="container"><p class="profile-head__bio">{{ $user->bio }}</p></div>
    @endif
    @if ($isOwner && ! $user->is_public)
        <div class="container"><p class="notice">Профиль скрыт, эту страницу видите только вы.</p></div>
    @endif
</section>

<section class="container kpis">
    <div class="tile">
        <span class="tile__value">{{ $summary['done'] }}<small>/{{ $summary['total'] }}</small></span>
        <span class="tile__label">целей выполнено</span>
    </div>
    <div class="tile">
        <span class="tile__value">{{ $summary['success_rate'] !== null ? $summary['success_rate'].'%' : '—' }}</span>
        <span class="tile__label">успешность<br><small class="muted">выполнено / (выполнено + провалено)</small></span>
    </div>
    <div class="tile">
        <span class="tile__value">{{ $summary['streak'] }}</span>
        <span class="tile__label">дней подряд</span>
    </div>
    <div class="tile">
        <span class="tile__value">{{ $summary['in_progress'] + $summary['planned'] }}</span>
        <span class="tile__label">в работе</span>
    </div>
    <div class="tile {{ $summary['overdue'] ? 'tile_alert' : '' }}">
        <span class="tile__value">{{ $summary['overdue'] }}</span>
        <span class="tile__label">{{ $summary['overdue'] ? '⚠ просрочено' : 'просрочек' }}</span>
    </div>
</section>

<section class="container section">
    <div class="section__head">
        <h2 class="section__title">Активность</h2>
        <div class="year-tabs" id="heatmap-years" role="tablist" aria-label="Год"></div>
    </div>
    <div class="card">
        <p class="heatmap-caption muted" id="heatmap-caption"></p>
        <div id="heatmap" class="heatmap" role="img" aria-label="Тепловая карта выполненных задач по дням"></div>
        <div class="heatmap-legend muted">
            меньше
            <span class="hm-cell" data-level="0"></span><span class="hm-cell" data-level="1"></span><span class="hm-cell" data-level="2"></span><span class="hm-cell" data-level="3"></span><span class="hm-cell" data-level="4"></span>
            больше
        </div>
    </div>
</section>

<section class="container section charts">
    <div class="card card_wide">
        <h3 class="card__title">Прогресс по месяцам</h3>
        <div class="chart-box"><canvas id="chart-timeline"></canvas></div>
    </div>
    <div class="card">
        <h3 class="card__title">По типам задач</h3>
        <div class="chart-box"><canvas id="chart-categories"></canvas></div>
    </div>
    <div class="card">
        <h3 class="card__title">По горизонту</h3>
        <div class="chart-box"><canvas id="chart-horizons"></canvas></div>
    </div>
</section>

@if (count($chartData['metrics']))
<section class="container section">
    <h2 class="section__title">Метрики</h2>
    <div class="metrics">
        @foreach ($chartData['metrics'] as $metric)
            @php($trend = $metric['trend'])
            <div class="card metric">
                <div class="metric__head">
                    <h3 class="card__title">{{ $metric['name'] }}</h3>
                    @if ($trend)
                        <span class="trend trend_{{ $trend['state'] }}">
                            {{ ['progress' => '▲ прогресс', 'regress' => '▼ регресс', 'flat' => '■ без изменений'][$trend['state']] }}
                        </span>
                    @endif
                </div>
                @if ($metric['last_text'])
                    <p class="metric__value">
                        {{ $metric['last_text'] }} {{ $metric['unit'] }}
                        @if ($trend)
                            <span class="muted">({{ $metric['delta_text'] }}@if ($trend['percent'] !== null), {{ $trend['percent'] > 0 ? '+' : '' }}{{ $trend['percent'] }}%@endif с первого замера)</span>
                        @endif
                    </p>
                    <p class="muted">
                        Последний замер {{ $metric['last_date'] }} · лучший результат {{ $metric['best_text'] }} {{ $metric['unit'] }} · замеров: {{ $metric['count'] }}
                    </p>
                @endif
                @if ($metric['target_text'])
                    <p class="muted">Цель: {{ $metric['target_text'] }} {{ $metric['unit'] }}</p>
                @endif
                <div class="chart-box chart-box_small"><canvas data-metric="{{ $metric['id'] }}"></canvas></div>
            </div>
        @endforeach
    </div>
</section>
@endif

<section class="container section">
    <h2 class="section__title">Текущие задачи</h2>
    <div class="board">
        @foreach ($board as $column)
            <div class="board__col">
                <h3 class="board__title">{{ $column['label'] }} <span class="muted">{{ $column['goals']->count() }}</span></h3>
                @forelse ($column['goals'] as $goal)
                    <article class="goal goal_{{ $goal->importance->value }} {{ $goal->isOverdue() ? 'goal_overdue' : '' }}">
                        <div class="goal__top">
                            <span class="chip chip_{{ $goal->importance->value }}">{{ $goal->importance->getLabel() }}</span>
                            @if ($goal->category)<span class="chip" style="--chip: {{ $goal->category->color }}">{{ $goal->category->label() }}</span>@endif
                        </div>
                        <h4 class="goal__title">{{ $goal->title }}</h4>
                        @if ($goal->parent)<p class="goal__parent muted">↳ {{ $goal->parent->title }}</p>@endif
                        <div class="bar bar_thin"><span style="width: {{ $goal->progress }}%"></span></div>
                        <p class="goal__meta muted">
                            {{ $goal->status->getLabel() }} · {{ $goal->progress }}%
                            @if ($goal->due_date) · до {{ $goal->due_date->format('d.m.Y') }}@endif
                            @if ($goal->isOverdue()) · <b class="danger">просрочено</b>@endif
                        </p>
                    </article>
                @empty
                    <p class="muted board__empty">Пусто</p>
                @endforelse
            </div>
        @endforeach
    </div>
</section>

@if ($lessons->isNotEmpty())
<section class="container section">
    <h2 class="section__title">Выводы</h2>
    <div class="lessons">
        @foreach ($lessons as $goal)
            <article class="card lesson">
                <p class="lesson__status lesson__status_{{ $goal->status->value }}">{{ $goal->status->getLabel() }} · {{ $goal->completed_at?->format('d.m.Y') }}</p>
                <h3 class="card__title">{{ $goal->title }}</h3>
                <p>{{ $goal->retrospective }}</p>
            </article>
        @endforeach
    </div>
</section>
@endif

@if ($gallery->isNotEmpty())
<section class="container section">
    <h2 class="section__title">Фото <span class="muted">{{ $gallery->count() }}</span></h2>
    <div class="gallery">
        @foreach ($gallery as $goal)
            <figure class="gallery__item">
                <a href="{{ Storage::disk('public')->url($goal->photo) }}" target="_blank" rel="noopener">
                    <img src="{{ Storage::disk('public')->url($goal->photo) }}" alt="{{ $goal->title }}" loading="lazy">
                </a>
                <figcaption>
                    <span>{{ $goal->title }}</span>
                    <span class="muted">
                        {{ ($goal->completed_at ?? $goal->created_at)->format('d.m.Y') }}
                        @if ($goal->link) · <a href="{{ $goal->link }}" target="_blank" rel="noopener">пост ↗</a>@endif
                    </span>
                </figcaption>
            </figure>
        @endforeach
    </div>
</section>
@endif

@if ($recentDone->isNotEmpty())
<section class="container section">
    <h2 class="section__title">Недавно выполнено</h2>
    <ul class="done-list">
        @foreach ($recentDone as $goal)
            <li>
                <span class="done-list__check" aria-hidden="true">✓</span>
                <span>{{ $goal->title }}</span>
                <span class="muted">
                    {{ $goal->completed_at?->format('d.m.Y') }}
                    @if ($goal->link) · <a href="{{ $goal->link }}" target="_blank" rel="noopener">пост ↗</a>@endif
                </span>
            </li>
        @endforeach
    </ul>
</section>
@endif

@if (count($chartData['places']))
<section class="container section">
    <h2 class="section__title">Карта мест <span class="muted">{{ count($chartData['places']) }}</span></h2>
    <div id="map" class="map"></div>
</section>
@endif
@endsection

@push('scripts')
    <script src="{{ asset('vendor/chartjs/chart.umd.min.js') }}"></script>
    <script src="{{ asset('vendor/leaflet/leaflet.js') }}"></script>
    <script>window.__PROFILE__ = @json($chartData);</script>
    <script src="{{ asset('js/profile.js') }}?v={{ filemtime(public_path('js/profile.js')) }}"></script>
@endpush
