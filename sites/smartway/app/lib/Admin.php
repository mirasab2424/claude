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
        'about' => 'О центре', 'contacts' => 'Контакты', 'diag' => 'Диагностика (тест)', '404' => 'Страница 404',
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
            $path === '/diag' => $this->diag($method),
            (bool) preg_match('~^/diag/(\d+)$~', $path, $m) => $this->diagAttempt((int) $m[1], $method),
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

    /** Диагностика: сводная статистика, список прохождений, демо-данные. */
    private function diag(string $method): void
    {
        $store = $this->app->diag();
        if ($method === 'POST') {
            $action = $_POST['action'] ?? '';
            if ($action === 'delete_demo') {
                $this->flash('Удалено демо-прохождений: ' . $store->deleteDemo() . '.');
            } elseif ($action === 'seed_demo') {
                $store->seedDemo();
                $this->flash('Добавлено 20 демо-прохождений.');
            }
            $this->app->redirect('/admin/diag');
        }
        $f = [
            'grade' => (int) ($_GET['grade'] ?? 0) ?: null,
            'lang' => in_array($_GET['lang'] ?? '', Content::LANGS, true) ? $_GET['lang'] : null,
            'demo' => !isset($_GET['real']),
        ];
        $data = $store->data();
        $topics = Content::localize($data['topics'], 'ru');
        $stats = $store->stats($f);
        $usedTopics = [];
        foreach ($stats['heat'] as $row) {
            $usedTopics += array_fill_keys(array_keys($row), true);
        }
        echo $this->view('diag', [
            'f' => $f,
            'stats' => $stats,
            'rows' => $store->list($f, 200),
            'grades' => $store->grades(),
            'topics' => $topics,
            'heat_topics' => array_values(array_filter(array_keys($topics), fn ($k) => isset($usedTopics[$k]))),
            'demo_count' => $store->demoCount(),
            'statuses' => Leads::STATUSES,
            'query' => http_build_query(array_filter(['grade' => $f['grade'], 'lang' => $f['lang'], 'real' => $f['demo'] ? null : 1])),
        ]);
    }

    private function diagAttempt(int $id, string $method): void
    {
        $store = $this->app->diag();
        $a = $store->get($id);
        $test = $a ? $store->test((int) $a['grade']) : null;
        if (!$a || !$test) {
            http_response_code(404);
            echo $this->view('message', ['title' => 'Не найдено', 'text' => '']);

            return;
        }
        if ($method === 'POST') {
            $action = $_POST['action'] ?? '';
            if ($action === 'delete') {
                $store->delete($id);
                $this->flash('Прохождение удалено.');
                $this->app->redirect('/admin/diag');
            }
            if ($action === 'score' && isset($a['answers'][$_POST['q'] ?? ''])) {
                $v = $_POST['value'] ?? 'auto';
                $store->override($a, (string) $_POST['q'], $v === 'auto' ? null : (float) $v);
                $this->flash('Балл пересчитан.');
            }
            if ($action === 'status' && $a['lead_id']) {
                $this->app->leads->update((int) $a['lead_id'], (string) ($_POST['status'] ?? ''), (string) ($_POST['note'] ?? ''));
                $this->flash('Статус заявки сохранён.');
            }
            $this->app->redirect('/admin/diag/' . $id);
        }
        $lead = null;
        if ($a['lead_id']) {
            $st = $this->app->leads->db()->prepare('SELECT * FROM leads WHERE id = ?');
            $st->execute([$a['lead_id']]);
            $lead = $st->fetch() ?: null;
        }
        $topics = Content::localize($store->data()['topics'], 'ru');
        $items = [];
        foreach ($test['questions'] as $i => $q) {
            $ans = $a['answers'][$q['id']] ?? ['given' => null, 'score' => 0, 'max' => $q['points'], 'auto' => 0];
            $c = $q['check'];
            $given = $ans['given'];
            if ($c['type'] === 'choice' && $given !== null && $given !== '') {
                $given = ($c['options'][(int) $given]['ru'] ?? '?');
            }
            $items[] = [
                'id' => $q['id'], 'n' => $i + 1, 'text' => $q['text']['ru'], 'topic' => $topics[$q['topic']] ?? $q['topic'],
                'given' => $given, 'score' => $ans['score'], 'max' => $ans['max'], 'manual' => !empty($ans['manual']),
                'key' => self::answerKey($q),
            ];
        }
        $pct = rtrim(rtrim(number_format((float) $a['percent'], 1, ',', ''), '0'), ',');
        $wa = 'Здравствуйте! Это SMART WAY. ' . $a['name'] . ' прошёл(а) диагностику по математике за ' . $a['grade'] . ' класс: ' . $pct . '%. Предлагаем бесплатно разобрать ошибки с преподавателем. Когда вам удобно?';
        echo $this->view('diag_attempt', [
            'a' => $a, 'items' => $items, 'lead' => $lead, 'statuses' => Leads::STATUSES, 'level' => \Smart\Diag\Store::level((float) $a['percent']),
            'topics' => $topics, 'pct' => $pct,
            'wa' => 'https://wa.me/' . preg_replace('~\D~', '', preg_replace('~^8~', '7', preg_replace('~\D~', '', $a['phone']))) . '?text=' . rawurlencode($wa),
        ]);
    }

    /** Правильный ответ для учителя — коротко, по-русски. */
    private static function answerKey(array $q): string
    {
        $c = $q['check'];
        $fmt = fn ($v) => rtrim(rtrim(number_format((float) $v, 3, ',', ''), '0'), ',');

        return match ($c['type']) {
            'choice' => $c['options'][$c['answer']]['ru'],
            'number' => implode(' или ', array_map($fmt, $c['answer'])),
            'seq' => implode('; ', array_map($fmt, $c['answer'])) . (($c['ordered'] ?? true) ? '' : ' (в любом порядке)'),
            'pairs' => implode('; ', array_map(fn ($p) => '(' . $fmt($p[0]) . '; ' . $fmt($p[1]) . ')', $c['answer'])),
            'expr' => $c['answer'] . (!empty($c['product']) ? ' (в виде произведения)' : ''),
            'ineq' => $c['answer'],
            'letters' => strtoupper(implode(', ', $c['answer'])),
            'keywords', 'parts' => match ($q['id']) {
                'g7q1' => 'НОК = 1440; 3:4:5', 'g8q13' => '65°, остроугольный', 'g10q4' => 'треугольник с вершинами (0;0), (5;0), (0;5)',
                'g11q1' => 'D = R; E = R', 'g11q2' => 'чётная', 'g11q3' => 'сдвиг графика вверх на 2', 'g11q4' => 'x = 0 и y = 0', default => '',
            },
            default => '',
        };
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
