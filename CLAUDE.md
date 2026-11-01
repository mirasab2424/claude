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

## Второй проект: сайт SMART WAY (`sites/smartway/`)
Сайт образовательного центра клиента → smart.is-best.net (тот же FTP-аккаунт). PHP + Twig, три языка
(`/`, `/kz/`, `/en/`), админка `/admin` (заявки, контент, тексты, пароль).
Факты только в `sites/smartway/app/content/default.json` (из PDF-буклетов, Instagram @smartschool_ast, 2ГИС) — не выдумывать.
Тексты всегда `{"ru","kz","en"}`; строки интерфейса — `app/lang/*.php` с одинаковыми ключами.
Правки админки, заявки и фото живут на сервере (`app/storage`, `public/uploads`) и в репозиторий не попадают.
Диагностика по математике `/test` (5–11 кл.): тесты и ключи в `app/content/diagnostics.json`, проверка `app/lib/Diag/*`,
результаты в `diag_attempts` (storage/leads.sqlite) + заявка; демо-прохождения помечены `demo = 1`.
Деплой `.github/workflows/deploy-smartway.yml` (composer → dist → FTP). Трекерный деплой игнорирует `sites/**`.

## Третий проект: тесты Kostobe (`sites/kostobe/`)
Сайт онлайн-тестов → kostobe.site.je (тот же FTP-аккаунт). PHP + Twig + SQLite, три языка (`/`, `/kz/`, `/en/`),
кабинет учителя `/admin` (тесты, 7 видов заданий, статистика, предметы, учителя). Ответы проверяются только на сервере
по снимку вопросов в попытке. Примеры тестов — `app/content/samples.json` (загружаются в пустую базу).
База `app/storage/tests.sqlite` живёт на сервере. Деплой `.github/workflows/deploy-kostobe.yml`.

## Четвёртый проект: «Наш дом» (`sites/miras/`)
Статический сайт → miras.page.gd (корень `/htdocs/` того же FTP). Все числа — только в `public/data/measurements.json`
(по видео, масштаб-гипотеза, статуса measured нет); план, таблицы и 3D строятся из него в браузере.
Округление: дом 0,5 м, комнаты 0,1 м, с «≈». assumed отличается от estimated, электрику — только списком по комнатам,
лестница — «предварительно». Без адреса и координат. Видео и evidence/ в git не класть. Деплой вручную: `deploy-miras.yml`.
Участок и мебель будущего дизайна — `public/assets/site.glb` (из старой модели) + `public/data/design.json` (привязка и комнаты).
Ориентация: x вправо, если смотреть с улицы; в 3D y плана = −z, на 2D-плане улица снизу.

## Команды
- `php artisan migrate:fresh --seed`: база с демо-данными, админ `admin@example.com` / `password`.
- `php artisan test`: тесты в `tests/Feature`.
- `php artisan serve`: сайт на http://localhost:8000.
