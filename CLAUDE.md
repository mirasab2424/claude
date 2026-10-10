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
- Логотип только из `design/logo.blend`, ничего не рисуем вручную: `public/models/logo.glb` (экспорт
  `design/export_glb.py`, 17 клипов склеиваются в один в `public/js/logo3d.js`), `public/img/logo.png`
  и `favicon.png` — рендер `design/render_logo.py`. Blender ставится как `pip install bpy`.
- Все строки в HTML всплывающих окон карты проходят через `escapeHtml`.
- Данные пользователя — дневник из Telegram (`database/data/telegram/diary.json`, сид `TelegramDiarySeeder`).
  Демо-данных больше нет, ничего не выдумываем. Всё публично и только на `/u/miras`, не на главной.
- Метрики с `is_duration` хранят секунды, показываются как м:сс (`Metric::format`, `Metric::parseDuration`).
- Хостинг — InfinityFree (to-do-miras.page.gd), без SSH. Деплой автоматический: пуш → `.github/workflows/deploy.yml`
  → FTP (секреты `FTP_*` в GitHub). Миграции применяет первый запрос (`App\Support\PostDeploy`, метка `deploy-version`).
  Запасной ручной путь: `deploy/build-update.sh` + `deploy/import-telegram.php`. Из этой среды FTP недоступен.

## Команды
- `php artisan migrate:fresh --seed`: база с демо-данными, админ `admin@example.com` / `password`.
- `php artisan test`: тесты в `tests/Feature`.
- `php artisan serve`: сайт на http://localhost:8000.
