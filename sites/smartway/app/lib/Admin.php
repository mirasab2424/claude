<?php

namespace Smart;

/** Админка: /admin — заявки, редактирование контента на трёх языках, тексты интерфейса, пароль. */
final class Admin
{
    /** Разделы редактора: адрес → [название, ключи контента]. */
    public const SECTIONS = [
        'site' => ['Контакты и общее', ['site']],
        'branches' => ['Филиалы', ['branches']],
        'courses' => ['Курсы: карточки', ['courses', 'languages_extra']],
        'english' => ['Английский', ['english']],
        'ielts' => ['IELTS', ['ielts']],
        'math' => ['Математика', ['math']],
        'nis' => ['НИШ', ['nis']],
        'school' => ['Подготовка к школе', ['school']],
        'prodlenka' => ['Продлёнка', ['prodlenka']],
        'teachers' => ['Преподаватели', ['teachers', 'teacher_standards']],
        'home' => ['Главная и «О центре»', ['control', 'stats', 'steps', 'photos', 'books']],
    ];

    public const TEXT_GROUPS = [
        'common' => 'Общие (меню, кнопки, подвал)', 'lead' => 'Форма заявки', 'meta' => 'Заголовки и описания для поиска',
        'home' => 'Главная', 'cta' => 'Призывы', 'english' => 'Английский', 'ielts' => 'IELTS', 'math' => 'Математика', 'nis' => 'НИШ',
        'school' => 'Подготовка к школе', 'prodlenka' => 'Продлёнка', 'teachers' => 'Преподаватели', 'prices' => 'Цены',
        'about' => 'О центре', 'contacts' => 'Контакты', '404' => 'Страница 404',
    ];

    private \Twig\Environment $twig;

    public function __construct(private App $app)
    {
    }

    public function handle(string $path, string $method): void
    {
        header('X-Frame-Options: DENY');
        header('Cache-Control: no-store');
        header('X-Robots-Tag: noindex, nofollow');
        $this->twig = $this->app->twig('admin', 'ru');
        $auth = $this->app->auth;

        if ($path === '/login') {
            $this->login($method);

            return;
        }
        if (!$auth->check()) {
            $this->app->redirect('/admin/login');
        }
        if ($method === 'POST' && !$auth->validCsrf($_POST['_csrf'] ?? null)) {
            http_response_code(419);
            echo $this->view('message', ['title' => 'Сессия устарела', 'text' => 'Обновите страницу и попробуйте ещё раз.']);

            return;
        }

        match (true) {
            $path === '/' => $this->dashboard(),
            $path === '/logout' && $method === 'POST' => $this->logout(),
            $path === '/leads' => $this->leads($method),
            $path === '/leads.csv' => $this->csv(),
            $path === '/password' => $this->password($method),
            (bool) preg_match('~^/edit/([a-z]+)$~', $path, $m) && isset(self::SECTIONS[$m[1]]) => $this->edit($m[1], $method),
            (bool) preg_match('~^/texts/([a-z0-9]+)$~', $path, $m) && isset(self::TEXT_GROUPS[$m[1]]) => $this->texts($m[1], $method),
            default => $this->notFound(),
        };
    }

    private function view(string $tpl, array $vars = []): string
    {
        return $this->twig->render($tpl . '.twig', $vars + [
            'csrf' => $this->app->auth->csrf(),
            'sections' => array_map(fn ($s) => $s[0], self::SECTIONS),
            'text_groups' => self::TEXT_GROUPS,
            'new_leads' => $this->app->auth->check() ? $this->app->leads->counts()['new'] : 0,
            'initial_password' => $this->app->auth->check() && $this->app->auth->usesInitialPassword(),
            'flash' => $this->flash(),
            'uri' => strtok($_SERVER['REQUEST_URI'] ?? '/', '?'),
        ]);
    }

    private function flash(?string $msg = null): ?string
    {
        if ($msg !== null) {
            $_SESSION['flash'] = $msg;

            return null;
        }
        $m = $_SESSION['flash'] ?? null;
        unset($_SESSION['flash']);

        return $m;
    }

    private function notFound(): void
    {
        http_response_code(404);
        echo $this->view('message', ['title' => 'Нет такой страницы', 'text' => '']);
    }

    private function login(string $method): void
    {
        $auth = $this->app->auth;
        $error = null;
        if ($method === 'POST') {
            if (!$auth->validCsrf($_POST['_csrf'] ?? null)) {
                $error = 'Сессия устарела, попробуйте ещё раз.';
            } elseif ($auth->throttled(App::ip())) {
                $error = 'Слишком много попыток. Подождите 15 минут.';
            } elseif ($auth->login((string) ($_POST['password'] ?? ''), App::ip())) {
                $this->app->redirect('/admin');
            } else {
                $error = 'Неверный пароль.';
            }
        } elseif ($auth->check()) {
            $this->app->redirect('/admin');
        }
        echo $this->view('login', ['error' => $error]);
    }

    private function logout(): void
    {
        $this->app->auth->logout();
        $this->app->redirect('/admin/login');
    }

    private function dashboard(): void
    {
        echo $this->view('dashboard', ['counts' => $this->app->leads->counts(), 'leads' => array_slice($this->app->leads->list(), 0, 8), 'statuses' => Leads::STATUSES]);
    }

    private function leads(string $method): void
    {
        $leads = $this->app->leads;
        if ($method === 'POST') {
            $id = (int) ($_POST['id'] ?? 0);
            if (($_POST['action'] ?? '') === 'delete') {
                $leads->delete($id);
                $this->flash('Заявка удалена.');
            } else {
                $leads->update($id, (string) ($_POST['status'] ?? ''), (string) ($_POST['note'] ?? ''));
                $this->flash('Заявка №' . $id . ' сохранена.');
            }
            $this->app->redirect('/admin/leads' . (isset($_GET['status']) ? '?status=' . rawurlencode((string) $_GET['status']) : ''));
        }
        $status = isset($_GET['status']) ? (string) $_GET['status'] : null;
        echo $this->view('leads', ['leads' => $leads->list($status), 'status' => $status, 'statuses' => Leads::STATUSES, 'counts' => $leads->counts()]);
    }

    private function csv(): void
    {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="smartway-leads-' . date('Y-m-d') . '.csv"');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, ['№', 'Дата', 'Имя', 'Телефон', 'Ребёнок', 'Направление', 'Филиал', 'Язык', 'Страница', 'Статус', 'Заметка'], ';');
        foreach ($this->app->leads->list(null, 100000) as $l) {
            $row = [$l['id'], $l['created_at'], $l['name'], $l['phone'], $l['child'], $l['course'], $l['branch'], $l['lang'], $l['page'], Leads::STATUSES[$l['status']] ?? $l['status'], $l['note']];
            // Защита от формул при открытии в Excel
            fputcsv($out, array_map(fn ($v) => preg_match('~^[=+\-@\t\r]~', (string) $v) ? "'" . $v : $v, $row), ';');
        }
        fclose($out);
    }

    private function password(string $method): void
    {
        $error = null;
        if ($method === 'POST') {
            $new = (string) ($_POST['new'] ?? '');
            if (!password_verify((string) ($_POST['current'] ?? ''), $this->app->auth->hash())) {
                $error = 'Текущий пароль неверный.';
            } elseif (mb_strlen($new) < 10) {
                $error = 'Новый пароль — не короче 10 символов.';
            } elseif ($new !== ($_POST['repeat'] ?? '')) {
                $error = 'Пароли не совпадают.';
            } else {
                $this->app->auth->changePassword($new);
                $this->flash('Пароль изменён.');
                $this->app->redirect('/admin');
            }
        }
        echo $this->view('password', ['error' => $error]);
    }

    private function edit(string $section, string $method): void
    {
        [$title, $keys] = self::SECTIONS[$section];
        $content = $this->app->content;
        $defaults = $content->defaults();
        $errors = [];

        if ($method === 'POST') {
            if (($_POST['action'] ?? '') === 'reset') {
                foreach ($keys as $k) {
                    $content->reset($k);
                }
                $this->flash('Раздел «' . $title . '» возвращён к исходным данным.');
                $this->app->redirect('/admin/edit/' . $section);
            }
            $posted = $_POST['data'] ?? [];
            $errors = $this->applyUploads($posted);
            foreach ($keys as $k) {
                $content->save($k, Form::normalize($posted[$k] ?? null, $defaults[$k]));
            }
            $this->flash($errors ? 'Сохранено, но не все файлы загружены: ' . implode('; ', $errors) : 'Сохранено. Изменения уже на сайте.');
            $this->app->redirect('/admin/edit/' . $section);
        }

        $slugs = [];
        foreach ($content->get('courses') as $c) {
            $slugs[$c['slug']] = $c['short']['ru'] ?? $c['slug'];
        }
        $form = new Form($slugs);
        $html = '';
        foreach ($keys as $k) {
            $html .= is_array($defaults[$k]) && !Content::isLocalized($defaults[$k]) && !array_is_list($defaults[$k])
                ? '<section class="box"><h2>' . htmlspecialchars(Form::label($k)) . '</h2>' . $form->field([$k], $content->get($k), $defaults[$k], $k) . '</section>'
                : '<section class="box">' . $form->field([$k], $content->get($k), $defaults[$k], $k) . '</section>';
        }
        $page = match ($section) { 'site', 'branches' => 'contacts', 'courses', 'home' => 'index', default => $section };
        echo $this->view('edit', ['title' => $title, 'section' => $section, 'form' => $html, 'preview' => App::url($page)]);
    }

    /** Загруженные картинки: проверяем, что это изображение, уменьшаем до 1400 px и кладём в public/uploads. */
    private function applyUploads(array &$posted): array
    {
        $errors = [];
        foreach ($_FILES['up']['name'] ?? [] as $path => $name) {
            $err = $_FILES['up']['error'][$path];
            if ($err === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            $tmp = $_FILES['up']['tmp_name'][$path];
            if ($err !== UPLOAD_ERR_OK || !is_uploaded_file($tmp) || filesize($tmp) > 8 * 1024 * 1024) {
                $errors[] = $name . ' (файл больше 8 МБ или ошибка загрузки)';
                continue;
            }
            $info = @getimagesize($tmp);
            $ext = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp'][$info[2] ?? 0] ?? null;
            if (!$ext) {
                $errors[] = $name . ' (нужен JPG, PNG или WebP)';
                continue;
            }
            $dir = $this->app->public . '/uploads';
            if (!is_dir($dir)) {
                mkdir($dir, 0775, true);
            }
            $file = date('Ym') . '-' . bin2hex(random_bytes(5)) . '.' . $ext;
            if (!self::resize($tmp, $dir . '/' . $file, $info, $ext)) {
                move_uploaded_file($tmp, $dir . '/' . $file);
            }
            $ref = &$posted;
            foreach (explode('|', (string) $path) as $p) {
                if (!is_array($ref)) {
                    $ref = [];
                }
                $ref = &$ref[$p];
            }
            $ref = 'uploads/' . $file;
            unset($ref);
        }

        return $errors;
    }

    private static function resize(string $src, string $dst, array $info, string $ext): bool
    {
        [$w, $h] = $info;
        if (!function_exists('imagecreatetruecolor') || max($w, $h) <= 1400) {
            return false;
        }
        $img = match ($ext) { 'jpg' => @imagecreatefromjpeg($src), 'png' => @imagecreatefrompng($src), 'webp' => @imagecreatefromwebp($src) };
        if (!$img) {
            return false;
        }
        $k = 1400 / max($w, $h);
        $out = imagescale($img, (int) round($w * $k), (int) round($h * $k));
        $ok = match ($ext) { 'jpg' => imagejpeg($out, $dst, 85), 'png' => imagepng($out, $dst, 8), 'webp' => imagewebp($out, $dst, 85) };

        return (bool) $ok;
    }

    private function texts(string $group, string $method): void
    {
        $dir = $this->app->root . '/lang';
        $files = [];
        foreach (Content::LANGS as $l) {
            $files[$l] = I18n::file($dir, $l);
        }
        $keys = array_values(array_filter(array_keys($files['ru']), fn ($k) => self::groupOf($k) === $group));
        $ui = $this->app->content->get('ui', []);
        if (!is_array($ui) || array_is_list($ui)) {
            $ui = [];
        }

        if ($method === 'POST') {
            $in = $_POST['ui'] ?? [];
            foreach (Content::LANGS as $l) {
                foreach ($keys as $k) {
                    $v = trim(str_replace("\r\n", "\n", (string) ($in[$l][$k] ?? '')));
                    if ($v === '' || $v === ($files[$l][$k] ?? '')) {
                        unset($ui[$l][$k]);
                    } else {
                        $ui[$l][$k] = $v;
                    }
                }
                if (empty($ui[$l])) {
                    unset($ui[$l]);
                }
            }
            $this->app->content->save('ui', $ui ?: new \stdClass());
            $this->flash('Тексты сохранены.');
            $this->app->redirect('/admin/texts/' . $group);
        }

        $rows = [];
        foreach ($keys as $k) {
            $row = ['key' => $k];
            foreach (Content::LANGS as $l) {
                $row[$l] = $ui[$l][$k] ?? ($files[$l][$k] ?? '');
                $row[$l . '_changed'] = isset($ui[$l][$k]);
            }
            $rows[] = $row;
        }
        echo $this->view('texts', ['group' => $group, 'title' => self::TEXT_GROUPS[$group], 'rows' => $rows]);
    }

    private static function groupOf(string $key): string
    {
        $p = strstr($key, '.', true);
        if ($p === false || in_array($p, ['nav', 'price', 'footer'], true)) {
            return 'common';
        }

        return isset(self::TEXT_GROUPS[$p]) ? $p : 'common';
    }
}
