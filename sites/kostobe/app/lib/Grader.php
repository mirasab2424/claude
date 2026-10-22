<?php

namespace Kost;

/**
 * Подготовка вопросов для ученика (перемешивание, без правильных ответов) и проверка ответов.
 * Правильные ответы в браузер не уходят: снимок вопросов хранится в попытке, проверка — на сервере.
 */
final class Grader
{
    public const MARKS = [5 => 85, 4 => 65, 3 => 40];

    /** Снимок вопросов для попытки: порядок вопросов и вариантов фиксируется при старте. */
    public static function snapshot(array $test, array $questions): array
    {
        if ($test['shuffle_questions']) {
            shuffle($questions);
        }
        foreach ($questions as &$q) {
            $d = $q['data'];
            $view = [];
            if (in_array($q['type'], ['single', 'multiple'], true)) {
                $view = array_column($d['options'], 'id');
                if ($test['shuffle_options']) {
                    shuffle($view);
                }
            } elseif ($q['type'] === 'order') {
                $view = array_column($d['items'], 'id');
                // Порядок пунктов всегда перемешан, иначе ответ виден сразу
                for ($i = 0; $i < 5 && $view === array_column($d['items'], 'id'); $i++) {
                    shuffle($view);
                }
            } elseif ($q['type'] === 'match') {
                $view = array_column($d['pairs'], 'id');
                for ($i = 0; $i < 5 && $view === array_column($d['pairs'], 'id'); $i++) {
                    shuffle($view);
                }
            }
            $q['view'] = $view;
        }

        return array_values($questions);
    }

    /** Вопросы для страницы прохождения: только тексты на языке ученика, без отметок правильности. */
    public static function forStudent(array $snapshot, string $lang): array
    {
        $out = [];
        foreach ($snapshot as $n => $q) {
            $d = $q['data'];
            $v = ['id' => $q['id'], 'n' => $n + 1, 'type' => $q['type'], 'text' => L::t($q['text'], $lang), 'points' => $q['points']];
            switch ($q['type']) {
                case 'single':
                case 'multiple':
                    $by = array_column($d['options'], null, 'id');
                    $v['options'] = array_map(fn ($id) => ['id' => $id, 'text' => L::t($by[$id]['text'], $lang)], $q['view']);
                    break;
                case 'order':
                    $by = array_column($d['items'], null, 'id');
                    $v['items'] = array_map(fn ($id) => ['id' => $id, 'text' => L::t($by[$id]['text'], $lang)], $q['view']);
                    break;
                case 'match':
                    $by = array_column($d['pairs'], null, 'id');
                    $v['left'] = array_map(fn ($p) => ['id' => $p['id'], 'text' => L::t($p['left'], $lang)], $d['pairs']);
                    $v['right'] = array_map(fn ($id) => ['id' => $id, 'text' => L::t($by[$id]['right'], $lang)], $q['view']);
                    break;
                case 'number':
                    $v['unit'] = L::t($d['unit'] ?? [], $lang);
                    break;
            }
            $out[] = $v;
        }

        return $out;
    }

    public static function parseNumber(mixed $s): ?float
    {
        if (is_int($s) || is_float($s)) {
            return (float) $s;
        }
        $s = str_replace([' ', "\u{00A0}", ','], ['', '', '.'], trim((string) $s));
        $s = str_replace(['−', '–'], '-', $s);

        return is_numeric($s) ? (float) $s : null;
    }

    public static function normText(string $s): string
    {
        $s = mb_strtolower(trim($s));
        $s = str_replace('ё', 'е', $s);
        $s = preg_replace('~[\s\x{00A0}]+~u', ' ', $s);

        return trim($s, " .,!?;:«»\"'");
    }

    /**
     * Проверяет ответы. $posted — массив q[<id>] из формы.
     * @return array{answers: array, score: float, max: float, percent: float}
     */
    public static function grade(array $snapshot, array $posted): array
    {
        $answers = [];
        $score = 0.0;
        $max = 0.0;
        foreach ($snapshot as $q) {
            $d = $q['data'];
            $raw = $posted[$q['id']] ?? null;
            $given = null;
            $part = 0.0; // доля от 0 до 1
            switch ($q['type']) {
                case 'single':
                    $given = is_string($raw) && in_array($raw, array_column($d['options'], 'id'), true) ? $raw : null;
                    $right = array_column(array_filter($d['options'], fn ($o) => $o['correct']), 'id');
                    $part = $given !== null && in_array($given, $right, true) ? 1 : 0;
                    break;
                case 'multiple':
                    $all = array_column($d['options'], 'id');
                    $given = array_values(array_intersect($all, is_array($raw) ? array_map('strval', $raw) : []));
                    $right = array_column(array_filter($d['options'], fn ($o) => $o['correct']), 'id');
                    $hit = count(array_intersect($given, $right));
                    $miss = count(array_diff($given, $right));
                    if (!empty($d['partial'])) {
                        $part = $right ? max(0, ($hit - $miss) / count($right)) : 0;
                    } else {
                        $part = ($hit === count($right) && $miss === 0) ? 1 : 0;
                    }
                    break;
                case 'truefalse':
                    $given = $raw === '1' ? true : ($raw === '0' ? false : null);
                    $part = $given !== null && $given === (bool) $d['answer'] ? 1 : 0;
                    break;
                case 'text':
                    $given = is_string($raw) ? mb_substr(trim($raw), 0, 300) : '';
                    $ok = [];
                    foreach ($d['answers'] as $list) {
                        foreach ($list as $a) {
                            $ok[] = self::normText($a);
                        }
                    }
                    $part = $given !== '' && in_array(self::normText($given), $ok, true) ? 1 : 0;
                    break;
                case 'number':
                    $given = is_string($raw) ? mb_substr(trim($raw), 0, 40) : '';
                    $n = self::parseNumber($given);
                    $part = $n !== null && abs($n - (float) $d['answer']) <= (float) $d['tolerance'] + 1e-9 ? 1 : 0;
                    break;
                case 'order':
                    $all = array_column($d['items'], 'id');
                    $given = is_array($raw) ? array_values(array_unique(array_intersect(array_map('strval', $raw), $all))) : [];
                    if (count($given) === count($all)) {
                        $same = 0;
                        foreach ($all as $i => $id) {
                            $same += ($given[$i] ?? null) === $id ? 1 : 0;
                        }
                        $part = $same === count($all) ? 1 : $same / count($all) * 0.5;
                    } else {
                        $given = [];
                    }
                    break;
                case 'match':
                    $all = array_column($d['pairs'], 'id');
                    $given = [];
                    $same = 0;
                    foreach ($all as $id) {
                        $g = is_array($raw) && isset($raw[$id]) && in_array((string) $raw[$id], $all, true) ? (string) $raw[$id] : null;
                        $given[$id] = $g;
                        $same += $g === $id ? 1 : 0;
                    }
                    $part = $all ? $same / count($all) : 0;
                    break;
            }
            $pts = round($q['points'] * $part, 2);
            $score += $pts;
            $max += $q['points'];
            $answers[$q['id']] = ['given' => $given, 'score' => $pts, 'max' => $q['points'], 'correct' => $part >= 1];
        }
        $percent = $max > 0 ? round($score / $max * 100, 1) : 0.0;

        return ['answers' => $answers, 'score' => round($score, 2), 'max' => $max, 'percent' => $percent];
    }

    public static function mark(float $percent, array $marks = self::MARKS): int
    {
        foreach ([5, 4, 3] as $m) {
            if ($percent >= (float) ($marks[$m] ?? self::MARKS[$m])) {
                return $m;
            }
        }

        return 2;
    }

    /** Разбор ответа для страницы результата: что ответил ученик и как правильно. */
    public static function review(array $snapshot, array $answers, string $lang): array
    {
        $out = [];
        foreach ($snapshot as $n => $q) {
            $d = $q['data'];
            $a = $answers[$q['id']] ?? ['given' => null, 'score' => 0, 'max' => $q['points'], 'correct' => false];
            $g = $a['given'];
            $r = ['n' => $n + 1, 'type' => $q['type'], 'text' => L::t($q['text'], $lang), 'score' => $a['score'], 'max' => $a['max'],
                'correct' => $a['correct'], 'partial' => !$a['correct'] && $a['score'] > 0, 'explain' => L::t($d['explain'] ?? [], $lang)];
            switch ($q['type']) {
                case 'single':
                case 'multiple':
                    $by = array_column($d['options'], null, 'id');
                    $r['options'] = array_map(fn ($id) => [
                        'text' => L::t($by[$id]['text'], $lang),
                        'chosen' => is_array($g) ? in_array($id, $g, true) : $g === $id,
                        'right' => $by[$id]['correct'],
                    ], $q['view'] ?: array_keys($by));
                    break;
                case 'truefalse':
                    $r['given'] = $g;
                    $r['answer'] = (bool) $d['answer'];
                    break;
                case 'text':
                    $r['given'] = (string) $g;
                    $r['answer'] = implode(' / ', $d['answers'][$lang] ?: (array_values(array_filter($d['answers']))[0] ?? []));
                    break;
                case 'number':
                    $r['given'] = (string) $g;
                    $unit = L::t($d['unit'] ?? [], $lang);
                    $num = rtrim(rtrim(number_format((float) $d['answer'], 6, ',', ''), '0'), ',');
                    $r['answer'] = $num . ($d['tolerance'] > 0 ? ' ± ' . rtrim(rtrim(number_format((float) $d['tolerance'], 6, ',', ''), '0'), ',') : '') . ($unit !== '' ? ' ' . $unit : '');
                    break;
                case 'order':
                    $by = array_column($d['items'], null, 'id');
                    $r['given_list'] = array_map(fn ($id) => L::t($by[$id]['text'], $lang), $g ?: []);
                    $r['answer_list'] = array_map(fn ($it) => L::t($it['text'], $lang), $d['items']);
                    break;
                case 'match':
                    $by = array_column($d['pairs'], null, 'id');
                    $r['pairs'] = array_map(fn ($p) => [
                        'left' => L::t($p['left'], $lang),
                        'given' => isset($g[$p['id']]) ? L::t($by[$g[$p['id']]]['right'], $lang) : null,
                        'right' => L::t($p['right'], $lang),
                        'ok' => ($g[$p['id']] ?? null) === $p['id'],
                    ], $d['pairs']);
                    break;
            }
            $out[] = $r;
        }

        return $out;
    }
}
