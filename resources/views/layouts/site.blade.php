<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'Success') — трекер целей и прогресса</title>
    <meta name="description" content="@yield('description', 'Цели, задачи, метрики и карта мест — аналитика прогресса (или регресса) в одном месте.')">
    <link rel="icon" href="{{ asset('img/favicon.png') }}" type="image/png">
    <link rel="stylesheet" href="{{ asset('vendor/fonts/fonts.css') }}">
    <link rel="stylesheet" href="{{ asset('css/site.css') }}?v={{ filemtime(public_path('css/site.css')) }}">
    <script type="importmap">
        { "imports": { "three": "{{ asset('vendor/three/three.module.min.js') }}", "three/addons/": "{{ asset('vendor/three/addons') }}/" } }
    </script>
    <meta name="logo-model" content="{{ asset('models/logo.glb') }}?v={{ filemtime(public_path('models/logo.glb')) }}">
    @stack('head')
</head>
<body>
    <header class="site-header">
        <div class="container site-header__inner">
            <a href="{{ route('home') }}" class="brand">
                <img src="{{ asset('img/logo.png') }}" alt="" class="brand__logo">
                <span class="brand__name">success</span>
            </a>
            <nav class="site-nav">
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

    <main>
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
