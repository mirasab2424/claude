<?php

namespace Kost;

/** Админка учителя: тесты и редактор вопросов, статистика, предметы, учителя, настройки. */
final class Admin
{
    private \Twig\Environment $twig;
    private array $user;

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
        $user = $auth->user();
        if (!$user) {
            $this->app->redirect('/admin/login');
        }
        $this->user = $user;
        if ($method === 'POST' && !$auth->validCsrf($_POST['_csrf'] ?? null)) {
            http_response_code(419);
            echo $this->view('message', ['title' => 'Сессия устарела', 'text' => 'Обновите страницу и повторите действие.']);

            return;
        }
        $id = preg_match('~/(\d+)(?:/|$)~', $path, $m) ? (int) $m[1] : 0;

        match (true) {
            $path === '/' => $this->dashboard(),
            $path === '/logout' && $method === 'POST' => $this->logout(),
            $path === '/tests' => $this->tests(),
            $path === '/tests/new' => $this->edit(null, $method),
            (bool) preg_match('~^/tests/\d+$~', $path) => $this->edit($id, $method),
            (bool) preg_match('~^/tests/\d+/(copy|delete|publish)$~', $path, $a) && $method === 'POST' => $this->testAction($id, $a[1]),
            $path === '/stats' => $this->stats(),
            $path === '/stats.csv' => $this->csv(),
            (bool) preg_match('~^/attempts/\d+$~', $path) => $this->attempt($id, $method),
            $path === '/subjects' => $this->subjects($method),
            $path === '/teachers' && $this->user['is_admin'] => $this->teachers($method),
            $path === '/settings' && $this->user['is_admin'] => $this->settings($method),
            $path === '/password' => $this->password($method),
            default => $this->notFound(),
        };
    }

    private function view(string $tpl, array $vars = []): string
    {
        return $this->twig->render($tpl . '.twig', $vars + [
            'csrf' => $this->app->auth->csrf(),
            'me' => $this->user ?? null,
            'flash' => $this->flash(),
            'uri' => strtok($_SERVER['REQUEST_URI'] ?? '/', '?'),
            'types' => Tests::TYPES,
            'langs' => L::LANGS,
            'initial_pw' => isset($this->user) && $this->user['password'] === $this->app->initialHash,
        ]);
    }

    private function flash(?string $msg = null, string $kind = 'ok'): ?array
    {
        if ($msg !== null) {
            $_SESSION['flash'] = ['text' => $msg, 'kind' => $kind];

            return null;
        }
        $m = $_SESSION['flash'] ?? null;
        unset($_SESSION['flash']);

        return $m;
    }

    private function notFound(): void
    {
        http_response_code(404);
        echo $this->view('message', ['title' => 'Не найдено', 'text' => 'Такой страницы нет или у вас нет к ней доступа.']);
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
            } elseif ($auth->login((string) ($_POST['login'] ?? ''), (string) ($_POST['password'] ?? ''), App::ip())) {
                $this->app->redirect('/admin');
            } else {
                $error = 'Неверный логин или пароль.';
            }
        } elseif ($auth->user()) {
            $this->app->redirect('/admin');
        }
        echo $this->view('login', ['error' => $error, 'login' => (string) ($_POST['login'] ?? '')]);
    }

    private function logout(): void
    {
        $this->app->auth->logout();
        $this->app->redirect('/admin/login');
    }

    /** Учитель видит свои тесты, администратор — все. */
    private function scope(): ?int
    {
        return $this->user['is_admin'] ? null : (int) $this->user['id'];
    }

    private function ownTest(int $id): ?array
    {
        $t = $this->app->tests->find($id);
        if (!$t || ($this->scope() !== null && (int) $t['teacher_id'] !== $this->scope())) {
            return null;
        }

        return $t;
    }

    private function dashboard(): void
    {
        $db = $this->app->db;
        $tests = $this->app->tests->list($this->scope());
        $ids = array_column($tests, 'id') ?: [0];
        $in = implode(',', array_map('intval', $ids));
        $stats = $db->one("SELECT COUNT(*) n, AVG(percent) avg, SUM(started_at >= ?) week FROM attempts WHERE finished_at IS NOT NULL AND test_id IN ($in)",
            [date('Y-m-d H:i:s', time() - 7 * 86400)]);
        $recent = $db->all("SELECT a.*, t.title FROM attempts a JOIN tests t ON t.id = a.test_id WHERE a.finished_at IS NOT NULL AND a.test_id IN ($in) ORDER BY a.finished_at DESC LIMIT 10");
        foreach ($recent as &$r) {
            $r['title'] = json_decode($r['title'], true);
        }
        unset($r);
        echo $this->view('dashboard', [
            'tests' => $tests,
            'published' => count(array_filter($tests, fn ($t) => $t['published'])),
            'stats' => $stats,
            'recent' => $recent,
        ]);
    }

    private function tests(): void
    {
        $tests = $this->app->tests->list($this->scope());
        foreach ($tests as &$t) {
            $missing = [];
            $qs = $this->app->tests->questions((int) $t['id']);
            foreach ($t['langs'] as $l) {
                if (!L::has($t['title'], $l) || array_filter($qs, fn ($q) => Tests::missing($q, $l))) {
                    $missing[] = $l;
                }
            }
            $t['missing'] = $missing;
        }
        unset($t);
        echo $this->view('tests', ['tests' => $tests]);
    }

    private function subjectsList(): array
    {
        return array_map(fn ($s) => ['id' => (int) $s['id'], 'name' => json_decode($s['name'], true), 'position' => (int) $s['position']],
            $this->app->db->all('SELECT * FROM subjects ORDER BY position, id'));
    }

    private function edit(?int $id, string $method): void
    {
        $test = $id ? $this->ownTest($id) : null;
        if ($id && !$test) {
            $this->notFound();

            return;
        }
        $warnings = [];
        if ($method === 'POST') {
            $meta = [
                'title' => $_POST['title'] ?? [],
                'description' => $_POST['description'] ?? [],
                'subject_id' => ($sid = (int) ($_POST['subject_id'] ?? 0)) && $this->app->db->value('SELECT 1 FROM subjects WHERE id = ?', [$sid]) ? $sid : null,
                'langs' => $_POST['langs'] ?? [],
                'grades' => $_POST['grades'] ?? '',
                'time_limit' => $_POST['time_limit'] ?? 0,
                'shuffle_questions' => !empty($_POST['shuffle_questions']),
                'shuffle_options' => !empty($_POST['shuffle_options']),
                'show_answers' => !empty($_POST['show_answers']),
                'published' => !empty($_POST['published']),
                'teacher_id' => (int) $this->user['id'],
            ];
            $questions = json_decode((string) ($_POST['questions'] ?? '[]'), true);
            $questions = is_array($questions) ? array_slice($questions, 0, 300) : [];
            foreach ($questions as $i => $q) {
                foreach (Tests::normalize(is_array($q) ? $q : [])['errors'] as $e) {
                    $warnings[] = 'Вопрос ' . ($i + 1) . ': ' . $e;
                }
            }
            if (!array_filter(L::clean($meta['title']))) {
                $meta['title']['ru'] = 'Тест без названия';
            }
            if ($meta['published'] && ($warnings || !$questions)) {
                $meta['published'] = false;
                $warnings[] = 'Тест сохранён как черновик: сначала исправьте ошибки' . ($questions ? '' : ' и добавьте вопросы') . '.';
            }
            $newId = $this->app->tests->save($id, $meta, $questions);
            $this->flash($warnings ? implode("\n", $warnings) : 'Тест сохранён.', $warnings ? 'warn' : 'ok');
            $this->app->redirect('/admin/tests/' . $newId);
        }

        $questions = $test ? $this->app->tests->questions((int) $test['id']) : [];
        echo $this->view('edit', [
            'test' => $test ?? ['id' => null, 'title' => [], 'description' => [], 'langs' => ['ru', 'kz', 'en'], 'subject_id' => null, 'grades' => '',
                'time_limit' => 0, 'shuffle_questions' => 0, 'shuffle_options' => 1, 'show_answers' => 1, 'published' => 0],
            'questions_json' => json_encode($questions, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP),
            'subjects' => $this->subjectsList(),
            'attempts' => $test ? (int) $this->app->db->value('SELECT COUNT(*) FROM attempts WHERE test_id = ?', [$test['id']]) : 0,
        ]);
    }

    private function testAction(int $id, string $action): void
    {
        $test = $this->ownTest($id);
        if (!$test) {
            $this->notFound();

            return;
        }
        if ($action === 'delete') {
            $this->app->db->run('DELETE FROM tests WHERE id = ?', [$id]);
            $this->flash('Тест «' . L::t($test['title'], 'ru') . '» удалён вместе с результатами.');
            $this->app->redirect('/admin/tests');
        }
        if ($action === 'publish') {
            $on = !$test['published'];
            if ($on && !$this->app->tests->questions($id)) {
                $this->flash('Нельзя опубликовать тест без вопросов.', 'warn');
            } else {
                $this->app->db->run('UPDATE tests SET published = ? WHERE id = ?', [$on ? 1 : 0, $id]);
                $this->flash($on ? 'Тест опубликован — ученики его видят.' : 'Тест снят с публикации.');
            }
            $this->app->redirect('/admin/tests');
        }
        // Копия: черновик с теми же вопросами
        $copy = $test;
        foreach (L::LANGS as $l) {
            if (L::has($copy['title'], $l)) {
                $copy['title'][$l] .= $l === 'en' ? ' (copy)' : ($l === 'kz' ? ' (көшірме)' : ' (копия)');
            }
        }
        $copy['published'] = false;
        $copy['teacher_id'] = (int) $this->user['id'];
        $qs = array_map(fn ($q) => ['id' => 0] + $q, $this->app->tests->questions($id));
        $newId = $this->app->tests->save(null, $copy, $qs);
        $this->flash('Создана копия теста. Она не опубликована.');
        $this->app->redirect('/admin/tests/' . $newId);
    }

    /** Фильтр попыток по тесту, классу и имени. */
    private function filteredAttempts(?int $testId, string $class, string $q, int $limit = 1000): array
    {
        $tests = $this->app->tests->list($this->scope());
        $ids = $testId ? array_intersect([$testId], array_column($tests, 'id')) : array_column($tests, 'id');
        $in = implode(',', array_map('intval', $ids ?: [0]));
        $where = ["a.finished_at IS NOT NULL", "a.test_id IN ($in)"];
        $args = [];
        if ($class !== '') {
            $where[] = 'a.class_name = ?';
            $args[] = mb_strtoupper($class);
        }
        if ($q !== '') {
            $where[] = 'a.full_name LIKE ?';
            $args[] = '%' . $q . '%';
        }
        $rows = $this->app->db->all('SELECT a.id, a.test_id, a.lang, a.full_name, a.class_name, a.subject, a.started_at, a.finished_at, a.score, a.max_score,
            a.percent, a.mark, a.answers, t.title FROM attempts a JOIN tests t ON t.id = a.test_id WHERE ' . implode(' AND ', $where)
            . ' ORDER BY a.finished_at DESC LIMIT ' . $limit, $args);
        foreach ($rows as &$r) {
            $r['title'] = json_decode($r['title'], true);
            $r['answers'] = json_decode((string) $r['answers'], true) ?: [];
        }

        return $rows;
    }

    private function stats(): void
    {
        $testId = (int) ($_GET['test'] ?? 0) ?: null;
        $class = trim((string) ($_GET['class'] ?? ''));
        $q = trim((string) ($_GET['q'] ?? ''));
        $tests = $this->app->tests->list($this->scope());
        $rows = $this->filteredAttempts($testId, $class, $q);
        $marks = [5 => 0, 4 => 0, 3 => 0, 2 => 0];
        $classes = [];
        foreach ($rows as $r) {
            $marks[(int) $r['mark']] = ($marks[(int) $r['mark']] ?? 0) + 1;
            $classes[$r['class_name']][] = (float) $r['percent'];
        }
        ksort($classes, SORT_NATURAL);
        $classStats = array_map(fn ($p) => ['n' => count($p), 'avg' => array_sum($p) / count($p)], $classes);

        // По вопросам — только для выбранного теста, по текущей версии вопросов
        $perQuestion = [];
        if ($testId && ($test = $this->ownTest($testId))) {
            foreach ($this->app->tests->questions($testId) as $n => $qq) {
                $sum = 0;
                $max = 0;
                $cnt = 0;
                $right = 0;
                foreach ($rows as $r) {
                    $a = $r['answers']['items'][$qq['id']] ?? null;
                    if ($a) {
                        $sum += $a['score'];
                        $max += $a['max'];
                        $cnt++;
                        $right += $a['correct'] ? 1 : 0;
                    }
                }
                $perQuestion[] = ['n' => $n + 1, 'type' => $qq['type'], 'text' => L::t($qq['text'], 'ru'), 'count' => $cnt,
                    'right' => $right, 'percent' => $max > 0 ? $sum / $max * 100 : null];
            }
        }
        $allClasses = array_column($this->app->db->all('SELECT DISTINCT class_name FROM attempts WHERE finished_at IS NOT NULL ORDER BY class_name'), 'class_name');
        echo $this->view('stats', [
            'tests' => $tests,
            'test_id' => $testId,
            'class' => $class,
            'q' => $q,
            'rows' => array_slice($rows, 0, 300),
            'total' => count($rows),
            'avg' => $rows ? array_sum(array_column($rows, 'percent')) / count($rows) : null,
            'avg_mark' => $rows ? array_sum(array_column($rows, 'mark')) / count($rows) : null,
            'best' => $rows ? max(array_column($rows, 'percent')) : null,
            'marks' => $marks,
            'class_stats' => $classStats,
            'per_question' => $perQuestion,
            'all_classes' => $allClasses,
            'query' => http_build_query(array_filter(['test' => $testId, 'class' => $class, 'q' => $q])),
        ]);
    }

    private function csv(): void
    {
        $rows = $this->filteredAttempts((int) ($_GET['test'] ?? 0) ?: null, trim((string) ($_GET['class'] ?? '')), trim((string) ($_GET['q'] ?? '')), 100000);
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="results-' . date('Y-m-d') . '.csv"');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, ['№', 'ФИО', 'Класс', 'Предмет', 'Тест', 'Язык', 'Начал', 'Завершил', 'Баллы', 'Макс.', '%', 'Оценка'], ';');
        foreach ($rows as $r) {
            $row = [$r['id'], $r['full_name'], $r['class_name'], $r['subject'], L::t($r['title'], 'ru'), strtoupper($r['lang']), $r['started_at'],
                $r['finished_at'], str_replace('.', ',', (string) $r['score']), str_replace('.', ',', (string) $r['max_score']),
                str_replace('.', ',', (string) $r['percent']), $r['mark']];
            fputcsv($out, array_map(fn ($v) => preg_match('~^[=+\-@\t\r]~', (string) $v) ? "'" . $v : $v, $row), ';');
        }
        fclose($out);
    }

    private function attempt(int $id, string $method): void
    {
        $a = $this->app->db->one('SELECT * FROM attempts WHERE id = ?', [$id]);
        $test = $a ? $this->ownTest((int) $a['test_id']) : null;
        if (!$a || !$test) {
            $this->notFound();

            return;
        }
        if ($method === 'POST' && ($_POST['action'] ?? '') === 'delete') {
            $this->app->db->run('DELETE FROM attempts WHERE id = ?', [$id]);
            $this->flash('Результат удалён.');
            $this->app->redirect('/admin/stats?test=' . $test['id']);
        }
        $answers = json_decode((string) $a['answers'], true) ?: [];
        echo $this->view('attempt', [
            'a' => $a,
            'test' => $test,
            'review' => $a['finished_at'] ? Grader::review(json_decode($a['questions'], true) ?: [], $answers['items'] ?? [], $a['lang']) : [],
            'late' => !empty($answers['late']),
        ]);
    }

    private function subjects(string $method): void
    {
        $db = $this->app->db;
        if ($method === 'POST') {
            $action = $_POST['action'] ?? '';
            $sid = (int) ($_POST['id'] ?? 0);
            $name = L::clean($_POST['name'] ?? []);
            if ($action === 'delete' && $sid) {
                $db->run('DELETE FROM subjects WHERE id = ?', [$sid]);
                $this->flash('Предмет удалён. Его тесты остались без предмета.');
            } elseif (array_filter($name)) {
                if ($sid) {
                    $db->run('UPDATE subjects SET name = ?, position = ? WHERE id = ?', [Db::json($name), (int) ($_POST['position'] ?? 0), $sid]);
                } else {
                    $db->run('INSERT INTO subjects (name, position) VALUES (?, ?)', [Db::json($name), (int) $db->value('SELECT COALESCE(MAX(position), 0) + 1 FROM subjects')]);
                }
                $this->flash('Предмет сохранён.');
            } else {
                $this->flash('Укажите название предмета.', 'warn');
            }
            $this->app->redirect('/admin/subjects');
        }
        $counts = array_column($db->all('SELECT subject_id, COUNT(*) n FROM tests GROUP BY subject_id'), 'n', 'subject_id');
        echo $this->view('subjects', ['subjects' => $this->subjectsList(), 'counts' => $counts]);
    }

    private function teachers(string $method): void
    {
        $db = $this->app->db;
        if ($method === 'POST') {
            $action = $_POST['action'] ?? '';
            $tid = (int) ($_POST['id'] ?? 0);
            $pw = (string) ($_POST['password'] ?? '');
            if ($action === 'delete' && $tid && $tid !== (int) $this->user['id']) {
                $db->run('DELETE FROM teachers WHERE id = ?', [$tid]);
                $this->flash('Учётная запись удалена. Её тесты остались.');
            } elseif ($action === 'password' && $tid) {
                if (mb_strlen($pw) < 8) {
                    $this->flash('Пароль — не короче 8 символов.', 'warn');
                } else {
                    $this->app->auth->setPassword($tid, $pw);
                    $this->flash('Пароль изменён.');
                }
            } elseif ($action === 'add') {
                $login = mb_strtolower(trim((string) ($_POST['login'] ?? '')));
                $name = trim((string) ($_POST['name'] ?? ''));
                if (!preg_match('~^[a-z0-9._-]{3,32}$~', $login)) {
                    $this->flash('Логин: 3–32 латинские буквы, цифры, точка, дефис или подчёркивание.', 'warn');
                } elseif ($db->value('SELECT 1 FROM teachers WHERE login = ?', [$login])) {
                    $this->flash('Такой логин уже есть.', 'warn');
                } elseif ($name === '' || mb_strlen($pw) < 8) {
                    $this->flash('Укажите имя и пароль не короче 8 символов.', 'warn');
                } else {
                    $db->run('INSERT INTO teachers (name, login, password, is_admin, created_at) VALUES (?,?,?,?,?)',
                        [$name, $login, password_hash($pw, PASSWORD_DEFAULT), !empty($_POST['is_admin']) ? 1 : 0, date('Y-m-d H:i:s')]);
                    $this->flash('Учитель добавлен. Передайте ему логин и пароль.');
                }
            }
            $this->app->redirect('/admin/teachers');
        }
        echo $this->view('teachers', ['teachers' => $db->all('SELECT t.*, (SELECT COUNT(*) FROM tests WHERE teacher_id = t.id) tests FROM teachers t ORDER BY t.id')]);
    }

    private function settings(string $method): void
    {
        if ($method === 'POST') {
            $this->app->db->setSetting('site_name', L::clean($_POST['site_name'] ?? []));
            $m = array_map(fn ($v) => max(0, min(100, (float) str_replace(',', '.', (string) $v))), (array) ($_POST['marks'] ?? []));
            if (isset($m[5], $m[4], $m[3]) && $m[5] > $m[4] && $m[4] > $m[3]) {
                $this->app->db->setSetting('marks', [5 => $m[5], 4 => $m[4], 3 => $m[3]]);
                $this->flash('Настройки сохранены. Новая шкала действует для новых результатов.');
            } else {
                $this->flash('Название сохранено. Шкала не изменена: пороги должны убывать (5 > 4 > 3).', 'warn');
            }
            $this->app->redirect('/admin/settings');
        }
        echo $this->view('settings', ['names' => $this->app->siteName(), 'marks' => $this->app->marks()]);
    }

    private function password(string $method): void
    {
        $error = null;
        if ($method === 'POST') {
            $new = (string) ($_POST['new'] ?? '');
            if (!password_verify((string) ($_POST['current'] ?? ''), $this->user['password'])) {
                $error = 'Текущий пароль неверный.';
            } elseif (mb_strlen($new) < 8) {
                $error = 'Новый пароль — не короче 8 символов.';
            } elseif ($new !== ($_POST['repeat'] ?? '')) {
                $error = 'Пароли не совпадают.';
            } else {
                $this->app->auth->setPassword((int) $this->user['id'], $new);
                $this->flash('Пароль изменён.');
                $this->app->redirect('/admin');
            }
        }
        echo $this->view('password', ['error' => $error]);
    }
}
