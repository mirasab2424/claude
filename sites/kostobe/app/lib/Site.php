<?php

namespace Kost;

/** Публичная часть: список тестов, старт (ФИО, класс, предмет), прохождение, результат. */
final class Site
{
    private \Twig\Environment $twig;

    public function __construct(private App $app, private string $lang)
    {
        $this->twig = $app->twig('site', $lang);
    }

    private function render(string $tpl, array $vars = [], array $alternates = []): void
    {
        $alt = [];
        foreach (L::LANGS as $l) {
            $alt[$l] = $alternates[$l] ?? App::url('', $l);
        }
        echo $this->twig->render($tpl . '.twig', $vars + ['alternates' => $alt]);
    }

    public function notFound(): void
    {
        http_response_code(404);
        $this->render('404');
    }

    public function home(): void
    {
        $tests = array_filter($this->app->tests->list(null, true), fn ($t) => $t['q_count'] > 0);
        $groups = [];
        foreach ($tests as $t) {
            $key = (string) ($t['subject_id'] ?? 0);
            $groups[$key] ??= ['name' => $t['subject_name'] ?? [], 'tests' => []];
            $groups[$key]['tests'][] = $t;
        }
        $this->render('home', ['groups' => array_values($groups), 'count' => count($tests)]);
    }

    private function visibleTest(int $id): ?array
    {
        $t = $this->app->tests->find($id);
        if (!$t || (!$t['published'] && !$this->app->auth->user())) {
            return null;
        }

        return $t;
    }

    public function start(int $id, string $method): void
    {
        $test = $this->visibleTest($id);
        if (!$test) {
            $this->notFound();

            return;
        }
        $questions = $this->app->tests->questions($id);
        $available = in_array($this->lang, $test['langs'], true);
        $errors = [];
        $old = ['full_name' => '', 'class_name' => ''];

        if ($method === 'POST' && $available && $questions) {
            $old = [
                'full_name' => trim(preg_replace('~\s+~u', ' ', (string) ($_POST['full_name'] ?? ''))),
                'class_name' => trim((string) ($_POST['class_name'] ?? '')),
            ];
            if (mb_strlen($old['full_name']) < 3 || mb_strlen($old['full_name']) > 120) {
                $errors['full_name'] = true;
            }
            if ($old['class_name'] === '' || mb_strlen($old['class_name']) > 20) {
                $errors['class_name'] = true;
            }
            $recent = (int) $this->app->db->value('SELECT COUNT(*) FROM attempts WHERE ip = ? AND started_at >= ?', [App::ip(), date('Y-m-d H:i:s', time() - 3600)]);
            if (($_POST['website'] ?? '') !== '' || $recent >= 60) {
                $errors['rate'] = true;
            }
            if (!$errors) {
                $token = bin2hex(random_bytes(16));
                $snapshot = Grader::snapshot($test, $questions);
                $this->app->db->run('INSERT INTO attempts (token, test_id, lang, full_name, class_name, subject, started_at, questions, ip) VALUES (?,?,?,?,?,?,?,?,?)', [
                    $token, $id, $this->lang, $old['full_name'], mb_strtoupper($old['class_name']),
                    L::t($test['subject_name'] ?? [], $this->lang), date('Y-m-d H:i:s'), Db::json($snapshot), App::ip(),
                ]);
                $this->app->redirect(App::url('a/' . $token, $this->lang));
            }
        }

        $alt = [];
        foreach (L::LANGS as $l) {
            $alt[$l] = App::url('t/' . $id, $l);
        }
        $this->render('start', [
            'test' => $test,
            'q_count' => count($questions),
            'max' => array_sum(array_column($questions, 'points')),
            'available' => $available,
            'errors' => $errors,
            'old' => $old,
            'types' => array_values(array_unique(array_column($questions, 'type'))),
        ], $alt);
    }

    private function attempt(string $token): ?array
    {
        $a = $this->app->db->one('SELECT * FROM attempts WHERE token = ?', [$token]);
        if (!$a) {
            return null;
        }
        $a['questions'] = json_decode($a['questions'], true) ?: [];
        $a['answers'] = $a['answers'] ? json_decode($a['answers'], true) : null;

        return $a;
    }

    public function take(string $token, string $method): void
    {
        $a = $this->attempt($token);
        $test = $a ? $this->app->tests->find((int) $a['test_id']) : null;
        if (!$a || !$test) {
            $this->notFound();

            return;
        }
        if ($a['finished_at']) {
            $this->app->redirect(App::url('r/' . $token, $a['lang']));
        }
        if ($a['lang'] !== $this->lang) {
            $this->app->redirect(App::url('a/' . $token, $a['lang']));
        }
        $deadline = $test['time_limit'] ? strtotime($a['started_at']) + $test['time_limit'] * 60 : null;

        if ($method === 'POST') {
            $g = Grader::grade($a['questions'], is_array($_POST['q'] ?? null) ? $_POST['q'] : []);
            $late = $deadline && time() > $deadline + 60;
            $this->app->db->run('UPDATE attempts SET finished_at=?, score=?, max_score=?, percent=?, mark=?, answers=? WHERE id=? AND finished_at IS NULL', [
                date('Y-m-d H:i:s'), $g['score'], $g['max'], $g['percent'], Grader::mark($g['percent'], $this->app->marks()),
                Db::json(['items' => $g['answers'], 'late' => $late]), $a['id'],
            ]);
            $this->app->redirect(App::url('r/' . $token, $this->lang));
        }

        $this->render('take', [
            'test' => $test,
            'attempt' => $a,
            'questions' => Grader::forStudent($a['questions'], $a['lang']),
            'seconds_left' => $deadline ? max(0, $deadline - time()) : null,
            'token' => $token,
        ], array_fill_keys(L::LANGS, App::url('a/' . $token, $a['lang'])));
    }

    public function result(string $token): void
    {
        $a = $this->attempt($token);
        $test = $a ? $this->app->tests->find((int) $a['test_id']) : null;
        if (!$a || !$test) {
            $this->notFound();

            return;
        }
        if (!$a['finished_at']) {
            $this->app->redirect(App::url('a/' . $token, $a['lang']));
        }
        $items = $a['answers']['items'] ?? [];
        $review = Grader::review($a['questions'], $items, $a['lang']);
        $alt = [];
        foreach (L::LANGS as $l) {
            $alt[$l] = App::url('r/' . $token, $l);
        }
        $this->render('result', [
            'test' => $test,
            'attempt' => $a,
            'review' => $review,
            'right' => count(array_filter($review, fn ($r) => $r['correct'])),
            'show_answers' => (bool) $test['show_answers'],
            'duration' => max(0, strtotime($a['finished_at']) - strtotime($a['started_at'])),
            'marks' => $this->app->marks(),
        ], $alt);
    }
}
