@extends('layouts.site')

@section('content')
<section class="hero">
    <div class="container hero__inner">
        <div class="hero__text">
            <p class="eyebrow">success · личный трекер жизни</p>
            <h1 class="hero__title">Цели, задачи и&nbsp;метрики.<br><span class="accent">Прогресс</span> — или регресс.</h1>
            <p class="hero__lead">
                Записывай задачи на год, месяц, неделю и день. Отмечай важность и тип, веди заметки по ходу дела
                и выводы после. Сайт сам посчитает статистику и покажет, куда ты движешься.
            </p>
            <div class="hero__actions">
                @auth
                    <a href="/cabinet" class="btn">Открыть кабинет</a>
                    <a href="{{ route('profile.show', auth()->user()) }}" class="btn btn_ghost">Моя страница</a>
                @else
                    <a href="/cabinet/register" class="btn">Начать бесплатно</a>
                    @if ($members->isNotEmpty())
                        <a href="{{ route('profile.show', $members->first()['user']) }}" class="btn btn_ghost">Посмотреть пример</a>
                    @endif
                @endauth
            </div>
        </div>
        <div class="hero__logo" data-logo3d aria-label="3D-логотип Success"></div>
    </div>
</section>

<section class="container totals">
    <div class="tile"><span class="tile__value">{{ $totals['users'] }}</span><span class="tile__label">участников</span></div>
    <div class="tile"><span class="tile__value">{{ $totals['goals'] }}</span><span class="tile__label">целей поставлено</span></div>
    <div class="tile"><span class="tile__value">{{ $totals['done'] }}</span><span class="tile__label">выполнено</span></div>
    <div class="tile"><span class="tile__value">{{ $totals['places'] }}</span><span class="tile__label">мест на карте</span></div>
</section>

<section class="container section" id="how">
    <h2 class="section__title">Как это работает</h2>
    <div class="steps">
        <article class="step">
            <span class="step__num">01</span>
            <h3>Поставь задачу</h3>
            <p>Важность: <b>необходимо</b>, <b>нужно</b> или <b>хочется</b>. Тип: спорт, знание, навык и т.д.
               Горизонт: год, месяц, неделя или день. Мелкие задачи можно вкладывать в большие цели.</p>
        </article>
        <article class="step">
            <span class="step__num">02</span>
            <h3>Веди заметки</h3>
            <p>Заметки помогают по ходу выполнения, их можно дополнять. Числовые метрики (вес, шаги, страницы)
               показывают динамику на графиках.</p>
        </article>
        <article class="step">
            <span class="step__num">03</span>
            <h3>Делай выводы</h3>
            <p>После выполнения или провала оставь комментарий: что сработало, что нет. Это база для анализа.</p>
        </article>
        <article class="step">
            <span class="step__num">04</span>
            <h3>Смотри аналитику</h3>
            <p>Уровень и опыт, процент успеха, серия дней подряд, тепловая карта активности,
               прогресс по месяцам и типам задач, карта посещённых мест.</p>
        </article>
    </div>
</section>

<section class="container section" id="members">
    <h2 class="section__title">Участники</h2>
    @if ($members->isEmpty())
        <p class="muted">Пока никого нет. <a href="/cabinet/register">Стань первым</a>.</p>
    @else
        <div class="members">
            @foreach ($members as ['user' => $member, 'summary' => $s])
                <a href="{{ route('profile.show', $member) }}" class="member">
                    <x-avatar :user="$member" size="56" />
                    <div class="member__body">
                        <div class="member__name">{{ $member->name }}</div>
                        <div class="member__meta">
                            Уровень {{ $s['level'] }} · {{ $s['done'] }}/{{ $s['total'] }} выполнено
                            @if ($s['success_rate'] !== null) · {{ $s['success_rate'] }}% успеха @endif
                        </div>
                        <div class="bar" role="img" aria-label="Прогресс уровня {{ $s['level_progress'] }}%"><span style="width: {{ $s['level_progress'] }}%"></span></div>
                    </div>
                </a>
            @endforeach
        </div>
    @endif
</section>

<section class="container section logo-story">
    <div>
        <h2 class="section__title">Логотип</h2>
        <p>Логотип собран в Blender. Вверху страницы та же модель в реальном времени: её можно покрутить
           мышкой, а клик собирает её заново. Справа исходный ролик.</p>
    </div>
    <video class="logo-story__video" src="{{ asset('media/logo.mp4') }}" muted loop playsinline controls preload="metadata"></video>
</section>
@endsection

@push('scripts')
    <script type="module" src="{{ asset('js/logo3d.js') }}"></script>
@endpush
