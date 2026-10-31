<?php

namespace Smart\Diag;

use Smart\App;
use Smart\Content;
use Smart\I18n;

/** Публичные страницы диагностики: /test (выбор класса и контакты), /test/a/{token} (тест), /test/r/{token} (результат). */
final class Pages
{
    private Store $store;
    private I18n $i18n;

    public function __construct(private App $app, private string $lang)
    {
        $this->store = $app->diag();
        $this->i18n = new I18n($app->root . '/lang', $lang, (array) $app->content->get('ui', []));
    }

    public function handle(array $parts, string $method): void
    {
        match (true) {
            $parts === [] => $this->start($method),
            count($parts) === 2 && $parts[0] === 'a' && ctype_xdigit($parts[1]) => $this->take($parts[1], $method),
            count($parts) === 2 && $parts[0] === 'r' && ctype_xdigit($parts[1]) => $this->result($parts[1]),
            default => $this->notFound(),
        };
    }

    private function notFound(): void
    {
        http_response_code(404);
        echo $this->app->render('404', $this->lang);
    }

    private function alt(string $path): array
    {
        $out = [];
        foreach (Content::LANGS as $l) {
            $out[$l] = App::url($path, $l);
        }

        return $out;
    }

    private function start(string $method): void
    {
        $grades = [];
        foreach ($this->store->data()['tests'] as $t) {
            $grades[] = ['grade' => (int) $t['grade'], 'questions' => count($t['questions']), 'minutes' => $t['minutes']];
        }
        $old = ['name' => '', 'phone' => '', 'grade' => (int) ($_GET['grade'] ?? 0)];
        $errors = [];
        if ($method === 'POST') {
            $old = [
                'name' => mb_substr(trim(strip_tags((string) ($_POST['name'] ?? ''))), 0, 80),
                'phone' => mb_substr(trim(strip_tags((string) ($_POST['phone'] ?? ''))), 0, 30),
                'grade' => (int) ($_POST['grade'] ?? 0),
            ];
            $digits = preg_replace('~\D~', '', $old['phone']);
            if (mb_strlen($old['name']) < 2) {
                $errors['name'] = true;
            }
            if (strlen($digits) < 10 || strlen($digits) > 15) {
                $errors['phone'] = true;
            }
            if (!$this->store->test($old['grade'])) {
                $errors['grade'] = true;
            }
            if (($_POST['website'] ?? '') !== '' || $this->store->recentFromIp(App::ip(), 60) >= 20) {
                $errors['rate'] = true;
            }
            if (!$errors) {
                $token = $this->store->start($old['grade'], $this->lang, $old['name'], $old['phone'], App::ip());
                $this->app->redirect(App::url('test/a/' . $token, $this->lang));
            }
        }
        echo $this->app->render('test', $this->lang, ['grades' => $grades, 'old' => $old, 'errors' => $errors], 'test');
    }

    private function questions(array $test): array
    {
        $out = [];
        foreach ($test['questions'] as $i => $q) {
            $c = $q['check'];
            $out[] = [
                'id' => $q['id'],
                'n' => $i + 1,
                'type' => $c['type'] === 'choice' ? 'choice' : 'text',
                'text' => Content::localize($q['text'], $this->lang),
                'points' => $q['points'],
                'options' => $c['type'] === 'choice' ? Content::localize($c['options'], $this->lang) : [],
                'hint' => isset($q['hint']) ? Content::localize($q['hint'], $this->lang) : $this->i18n->t('diag.hint.' . $c['type']),
                'math' => in_array($c['type'], ['number', 'seq', 'pairs', 'expr', 'ineq'], true),
            ];
        }

        return $out;
    }

    private function take(string $token, string $method): void
    {
        $a = $this->store->find($token);
        $test = $a ? $this->store->test((int) $a['grade']) : null;
        if (!$a || !$test) {
            $this->notFound();

            return;
        }
        if ($a['finished_at']) {
            $this->app->redirect(App::url('test/r/' . $token, $this->lang));
        }
        if ($method === 'POST') {
            $result = $this->store->grade($test, is_array($_POST['q'] ?? null) ? $_POST['q'] : []);
            $leadId = $this->app->leads->add([
                'name' => $a['name'],
                'phone' => $a['phone'],
                'child' => $a['grade'] . ' класс',
                'course' => sprintf('Диагностика по математике, %d класс — %s%%', $a['grade'], rtrim(rtrim(number_format($result['percent'], 1, ',', ''), '0'), ',')),
                'branch' => '',
                'lang' => $a['lang'],
                'page' => 'test',
                'ip' => App::ip(),
            ]);
            $this->store->finish($a, $result, $leadId, date('Y-m-d H:i:s'));
            $this->app->redirect(App::url('test/r/' . $token, $this->lang));
        }
        echo $this->app->render('test', $this->lang, [
            'attempt' => $a,
            'test' => Content::localize($test, $this->lang),
            'questions' => $this->questions($test),
            'started' => strtotime($a['created_at']),
            'token' => $token,
        ], 'test_take', $this->alt('test/a/' . $token));
    }

    private function result(string $token): void
    {
        $a = $this->store->find($token);
        $test = $a ? $this->store->test((int) $a['grade']) : null;
        if (!$a || !$test) {
            $this->notFound();

            return;
        }
        if (!$a['finished_at']) {
            $this->app->redirect(App::url('test/a/' . $token, $this->lang));
        }
        $topicNames = Content::localize($this->store->data()['topics'], $this->lang);
        $topics = [];
        foreach ($a['topics'] as $key => [$s, $m]) {
            $topics[] = ['key' => $key, 'name' => $topicNames[$key] ?? $key, 'percent' => $m ? $s / $m * 100 : 0, 'score' => $s, 'max' => $m];
        }
        usort($topics, fn ($x, $y) => $y['percent'] <=> $x['percent']);
        $map = [];
        foreach ($test['questions'] as $i => $q) {
            $ans = $a['answers'][$q['id']] ?? ['score' => 0, 'max' => $q['points'], 'given' => null];
            $frac = $ans['max'] ? $ans['score'] / $ans['max'] : 0;
            $map[] = ['n' => $i + 1, 'state' => ($ans['given'] === null || $ans['given'] === '') ? 'empty' : ($frac >= 1 ? 'ok' : ($frac > 0 ? 'part' : 'bad')),
                'text' => Content::localize($q['text'], $this->lang), 'score' => $ans['score'], 'max' => $ans['max']];
        }
        $courses = array_column(Content::localize($this->app->content->get('courses'), $this->lang), null, 'slug');
        $rec = array_values(array_filter([(int) $a['grade'] <= 6 ? ($courses['nis'] ?? null) : null, $courses['math'] ?? null]));
        foreach ($rec as &$c) {
            $c['url'] = App::url($c['slug'], $this->lang);
        }
        unset($c);
        $percent = (float) $a['percent'];
        $pct = rtrim(rtrim(number_format($percent, 1, ',', ''), '0'), ',');
        echo $this->app->render('test', $this->lang, [
            'attempt' => $a,
            'test' => Content::localize($test, $this->lang),
            'level' => Store::level($percent),
            'pct' => $pct,
            'topics' => $topics,
            'strong' => array_filter($topics, fn ($t) => $t['percent'] >= 75),
            'weak' => array_filter($topics, fn ($t) => $t['percent'] < 60),
            'map' => $map,
            'minutes' => max(1, (int) round((strtotime($a['finished_at']) - strtotime($a['created_at'])) / 60)),
            'courses' => $rec,
            'wa_text' => $this->i18n->t('diag.wa_message', $a['name'], $a['grade'], $pct),
        ], 'test_result', $this->alt('test/r/' . $token));
    }
}
