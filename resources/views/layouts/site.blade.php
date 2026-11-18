<!DOCTYPE html>
<html lang="ru">
<head>
    @php
        $pageTitle = isset($user) ? $user->name : 'Success';
        $pageDescription = isset($user)
            ? ($user->tagline ?: 'Цели, прогресс и статистика — '.$user->name)
            : 'Цели, задачи, метрики и карта мест — аналитика прогресса в одном месте.';
    @endphp
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $pageTitle }} — трекер целей и прогресса</title>
    <meta name="description" content="{{ $pageDescription }}">
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="success">
    <meta property="og:title" content="{{ $pageTitle }} — трекер целей и прогресса">
    <meta property="og:description" content="{{ $pageDescription }}">
    <meta property="og:url" content="{{ url()->current() }}">
    <link rel="canonical" href="{{ url()->current() }}">
    @if (isset($user) && ! $user->is_public)<meta name="robots" content="noindex">@endif
    <meta property="og:image" content="@yield('og_image', asset('img/og.png'))">
    <meta property="og:image:alt" content="Логотип success">
    <meta property="og:image:width" content="1200">
    <meta property="og:image:height" content="630">
    <meta name="twitter:card" content="summary_large_image">
    <link rel="icon" href="{{ asset('img/favicon.png') }}" type="image/png">
    @include('partials.pwa')
    <link rel="stylesheet" href="{{ asset('vendor/fonts/fonts.css') }}">
    <link rel="stylesheet" href="{{ asset('css/site.css') }}?v={{ filemtime(public_path('css/site.css')) }}">
    <script type="importmap">
        { "imports": { "three": "{{ asset('vendor/three/three.module.min.js') }}", "three/addons/": "{{ asset('vendor/three/addons') }}/" } }
    </script>
    <meta name="logo-model" content="{{ asset('models/logo.glb') }}?v={{ filemtime(public_path('models/logo.glb')) }}">
    @stack('head')
</head>
<body>
    <a href="#main-content" class="skip-link">Перейти к содержимому</a>
    <header class="site-header">
        <div class="container site-header__inner">
            <a href="{{ route('home') }}" class="brand">
                <img src="{{ asset('img/logo.png') }}" alt="" class="brand__logo">
                <span class="brand__name">success</span>
            </a>
            <nav class="site-nav" aria-label="Основная навигация">
                <a href="{{ route('home') }}#members">Участники</a>
                <a href="{{ route('home') }}#how">Как это работает</a>
                @auth
                    <a href="{{ route('profile.show', auth()->user()) }}">Моя страница</a>
                    <a href="/cabinet" class="btn btn_small">Кабинет</a>
                @else
                    <a href="/cabinet/login">Войти</a>
                    <a href="/cabinet/register" class="btn btn_small">Регистрация</a>
                @endauth
            </nav>
        </div>
    </header>

    <main id="main-content" tabindex="-1">
        @yield('content')
    </main>

    <footer class="site-footer">
        <div class="container site-footer__inner">
            <span>success · {{ date('Y') }}</span>
            <span class="muted">Прогресс измеряется. Регресс тоже.</span>
        </div>
    </footer>

    @stack('scripts')
</body>
</html>
