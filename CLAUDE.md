# success: заметки для Claude

Личный сайт-трекер пользователя: цели, задачи, метрики, карта мест и аналитика
прогресса. Регистрация открыта и для других людей. Общаемся на русском,
пользователь — веб-разработчик (раньше работал на October CMS).

## Стек
Laravel 13 + Filament 5, SQLite, PHP 8.3. Публичная часть на Blade, без сборщика.
Библиотеки лежат в `public/vendor` (Three.js 0.160, Chart.js 4.4.1, Leaflet 1.9.4, шрифты Inter и Unbounded).

## Главное
- Две панели Filament: `admin` (`/admin`, только `users.is_admin`) и `cabinet` (`/cabinet`, регистрация и профиль).
- Формы и таблицы общие, лежат в `app/Filament/Shared/*` и принимают флаг `admin`.
  Ресурсы кабинета фильтруют записи по `user_id = auth()->id()` в `getEloquentQuery()`
  и подставляют `user_id` при создании.
- `is_admin` намеренно **не** в `$fillable`. В админке он ставится через `forceFill`.
- В Filament-замыканиях параметры внедряются по имени: пишем `$query`, `$record`, `$state`, а не `$q`.
- Аналитика вся в `App\Services\UserStats`. Параметр `publicOnly` скрывает приватные записи.
- Публичная страница `/u/{username}`. Владелец видит на ней и приватные данные.
- 3D-логотип: `public/js/logo3d.js`, процедурная копия `design/logo.blend`.
- Все строки в HTML всплывающих окон карты проходят через `escapeHtml`.

## Команды
- `php artisan migrate:fresh --seed`: база с демо-данными, админ `admin@example.com` / `password`.
- `php artisan test`: тесты в `tests/Feature`.
- `php artisan serve`: сайт на http://localhost:8000.
