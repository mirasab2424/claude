# success — личный трекер целей и прогресса

Сайт «обо мне и для меня»: цели и задачи, метрики, карта мест и аналитика,
которая показывает прогресс (или регресс). Пользоваться могут и другие люди:
регистрация открыта, у каждого свой личный кабинет и своя публичная страница.

## Что есть

| Раздел | Адрес | Кто видит |
|---|---|---|
| Главная с 3D-логотипом, участниками и общей статистикой | `/` | все |
| Публичная страница человека с аналитикой | `/u/{username}` | все (если профиль публичный) |
| Личный кабинет: цели, метрики, места, профиль, графики | `/cabinet` | любой зарегистрированный |
| Админка: пользователи, типы задач, все данные сайта | `/admin` | только `is_admin` |

**Цели и задачи** повторяют структуру `design/TO_DO.xlsx`:

- важность: *необходимо / нужно / хочется*;
- тип: спорт, знание, навык… (типы настраиваются в админке);
- время задачи: *на год / месяц / неделю / день*;
- дата начала и дедлайн;
- **заметки**: используются во время выполнения и дополняются по ходу;
- **комментарии**: для анализа и выводов после выполнения;
- статус и прогресс в %;
- задачи можно вкладывать в цели (недельная задача внутри годовой цели);
- у каждой записи есть переключатель «показывать публично».

**Аналитика** (`app/Services/UserStats.php`):

- уровень и XP: выполненная цель даёт `важность × горизонт` очков (год 40, месяц 10, неделя 3, день 1);
- процент успеха считается по закрытым целям как `выполнено / (выполнено + провалено)`, отменённые не учитываются;
- серия: сколько дней подряд что-то выполнялось;
- просроченные задачи;
- тепловая карта активности за год (как на GitHub);
- графики по месяцам, по типам задач и по горизонту;
- **метрики** (вес, пробежка, сон…): график замеров, линия цели и метка
  «▲ прогресс» или «▼ регресс». Для каждой метрики задаётся, что лучше: больше или меньше;
- блок «Выводы» показывает комментарии к закрытым целям;
- карта посещённых мест (Leaflet).

**Логотип.** Всё берётся из `design/logo.blend`:
- `public/models/logo.glb` — модель с анимацией сборки, экспортированная из Blender. Её проигрывает
  `public/js/logo3d.js` (Three.js): логотип можно крутить мышкой, клик проигрывает сборку заново.
- `public/img/logo.png` и `public/img/favicon.png` — рендер последнего кадра с камеры из файла.
- `public/media/logo.mp4` — исходный ролик.

Если поменяете логотип в Blender, пересоберите файлы (нужен `pip install bpy`, Python 3.13):

```bash
python -I design/export_glb.py design/logo.blend public/models/logo.glb
python -I design/render_logo.py design/logo.blend /tmp/logo-render.png
convert /tmp/logo-render.png -trim +repage -resize x640 public/img/logo.png
```

## Стек и почему он

- **Laravel 13**: October CMS построен на Laravel, так что всё знакомо, но без ограничений CMS.
- **Filament 5**: бесплатная админка. Из коробки даёт вход, регистрацию, профиль, таблицы,
  фильтры, формы и графики. Здесь две панели: `admin` для обслуживания сайта и `cabinet` для пользователей.
- **SQLite**: база в одном файле, сервер БД не нужен. Если понадобится, можно переключить на MySQL в `.env`.
- Публичная часть написана на Blade и чистых CSS и JS, без сборщика. Three.js, Chart.js, Leaflet
  и шрифты лежат локально в `public/vendor`, поэтому CDN не нужен.

Всё бесплатное и open source.

## Запуск локально

Нужны PHP 8.3+ (с расширениями `pdo_sqlite`, `intl`, `zip`, `gd`) и Composer.

```bash
composer install
cp .env.example .env          # здесь же можно поменять ADMIN_EMAIL / ADMIN_PASSWORD
php artisan key:generate
touch database/database.sqlite   # Windows: type nul > database\database.sqlite
php artisan migrate --seed       # таблицы + типы задач + админ + демо-данные
php artisan storage:link         # для аватаров и фото мест (или PUBLIC_DISK_IN_WEBROOT=true в .env)
php artisan serve
```

Откройте http://localhost:8000.

Админ по умолчанию: `admin@example.com` / `password`. **Поменяйте пароль**
в кабинете (меню профиля) или задайте свои `ADMIN_*` в `.env` до запуска сида.

Демо-данные нужны только для того, чтобы графики не были пустыми. Начать с чистой базы:

```bash
php artisan migrate:fresh --seed
php artisan tinker --execute "App\Models\Goal::truncate(); App\Models\Metric::query()->delete(); App\Models\Place::truncate();"
```

Тесты:

```bash
php artisan test
```

## Структура

```
app/
  Enums/                  важность, горизонт, статус, направление метрики
  Models/                 User, Category, Goal, Metric, MetricEntry, Place
  Services/UserStats.php  вся аналитика
  Http/Controllers/       главная и публичная страница
  Filament/
    Shared/               формы и таблицы, общие для обеих панелей
    Admin/                ресурсы админки (+ пользователи, типы задач)
    Cabinet/              ресурсы кабинета (только свои записи), профиль, виджеты
  Providers/Filament/     настройка панелей admin и cabinet
resources/views/          layouts/site, home, profile
public/css/site.css       стили публичной части (тёмная тема в цветах логотипа)
public/js/logo3d.js       3D-логотип
public/js/profile.js      графики, тепловая карта, карта мест
design/                   исходники: logo.blend, TO_DO.xlsx
```

## Деплой на бесплатный хостинг без SSH (InfinityFree и похожие)

На таком хостинге нет консоли и Composer, поэтому сайт собирается целиком у себя:

```bash
APP_URL=http://miras.page.gd deploy/build-shared-hosting.sh
```

Скрипт создаёт `build/shared-hosting/`. Внутри папка `htdocs/` и она же,
разбитая на архивы `site-part*.zip` до 9 МБ. В сборке уже есть зависимости, готовая база SQLite
с админом (случайный пароль выводится в конце) и всё нужное для работы без symlink.
Ядро Laravel лежит в `htdocs/core` и закрыто от браузера через `.htaccess`.

Как залить:

1. Удалить из `htdocs` на хостинге стандартные файлы.
2. Загрузить архивы в `htdocs` через файловый менеджер и распаковать каждый на месте.
   Второй способ: залить содержимое `build/shared-hosting/htdocs/` по FTP через FileZilla.
3. В панели хостинга выбрать PHP 8.3 или новее.

### Автодеплой (GitHub Actions → FTP)

После каждого пуша в `main` или `claude/sharp-babbage-t64qnn` workflow `Deploy` прогоняет тесты,
собирает сайт (`deploy/build-ftp.sh`) и заливает по FTP только изменённые файлы. `.env`, база и
`storage` на сервере не трогаются. Библиотеки Composer (`core/vendor`, около 17 000 файлов) уходят отдельным
шагом и только когда меняется `composer.lock`. Залить их принудительно можно так: Actions → Deploy → Run workflow → with_vendor. Первый запрос после заливки сам применяет миграции (`App\Support\PostDeploy`).

Один раз нужно добавить секреты: Settings → Secrets and variables → Actions → New repository secret.

| Секрет | Значение |
|---|---|
| `FTP_SERVER` | `ftpupload.net` |
| `FTP_USERNAME` | `if0_43100396` |
| `FTP_PASSWORD` | пароль FTP из панели InfinityFree |
| `FTP_SERVER_DIR` | папка сайта на FTP, например `/to-do-miras.page.gd/htdocs/` (со слешем в конце) |

### Обновление сайта вручную

```bash
deploy/build-update.sh   # → build/update.zip
```

В архиве только код и статика, без `vendor`, базы, `.env` и загруженных файлов. Его распаковывают
в `htdocs` поверх старых файлов, данные и пароли при этом остаются. Если в обновлении есть новые миграции,
их применяет одноразовый скрипт `import-telegram.php` (см. ниже) или любой похожий.

### Дневник из Telegram

Данные канала t.me/successM2025 лежат в `database/data/telegram/diary.json` и `photos/`.
Их загружает `TelegramDiarySeeder`: он заменяет цели, метрики и места администратора.
Локально это делает `php artisan migrate:fresh --seed`. На хостинге нужно открыть
`/import-telegram.php?confirm=yes`, а после этого удалить файл.

## Деплой на хостинг с SSH

Подойдёт любой хостинг с PHP 8.3, в том числе обычный shared-хостинг. Корень сайта
указывайте на папку `public/`. После выкладки:

```bash
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan storage:link
php artisan optimize
php artisan filament:optimize
```

В `.env` на сервере: `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://ваш-домен`.

## Идеи на потом

- Привычки с ежедневными отметками (чек-лист дня) и их серии.
- Повторяющиеся задачи (каждый день, каждую неделю).
- Импорт и экспорт задач из Excel в формате `TO_DO.xlsx`.
- Друзья и подписки, сравнение прогресса, общие челленджи.
- Восстановление пароля по почте (нужно настроить SMTP в `.env`).
- Перенос мест и поездок из старого проекта на October CMS.
