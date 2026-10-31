<?php

namespace Smart\Diag;

use PDO;
use Smart\Content;

/**
 * Диагностика по математике: тесты из content/diagnostics.json, попытки в storage/leads.sqlite (таблица diag_attempts).
 * При первом создании таблицы добавляются демо-прохождения (demo = 1), их можно удалить в админке.
 */
final class Store
{
    public const LEVELS = [85 => 'high', 60 => 'good', 40 => 'base', 0 => 'low'];

    private ?array $data = null;

    public function __construct(private PDO $db, private string $file)
    {
        $exists = (bool) $db->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='diag_attempts'")->fetchColumn();
        $db->exec('CREATE TABLE IF NOT EXISTS diag_attempts (
            id INTEGER PRIMARY KEY AUTOINCREMENT, token TEXT NOT NULL UNIQUE, grade INTEGER NOT NULL, lang TEXT NOT NULL,
            name TEXT NOT NULL, phone TEXT NOT NULL, created_at TEXT NOT NULL, finished_at TEXT,
            score REAL, max_score REAL, percent REAL, answers TEXT, topics TEXT, demo INTEGER NOT NULL DEFAULT 0, lead_id INTEGER, ip TEXT
        )');
        $db->exec('CREATE INDEX IF NOT EXISTS diag_finished ON diag_attempts(finished_at)');
        if (!$exists) {
            $this->seedDemo();
        }
    }

    public function data(): array
    {
        return $this->data ??= json_decode((string) file_get_contents($this->file), true, 512, JSON_THROW_ON_ERROR);
    }

    public function test(int $grade): ?array
    {
        foreach ($this->data()['tests'] as $t) {
            if ((int) $t['grade'] === $grade) {
                return $t;
            }
        }

        return null;
    }

    public function grades(): array
    {
        return array_map(fn ($t) => (int) $t['grade'], $this->data()['tests']);
    }

    public static function level(float $p): string
    {
        foreach (self::LEVELS as $min => $key) {
            if ($p >= $min) {
                return $key;
            }
        }

        return 'low';
    }

    public function start(int $grade, string $lang, string $name, string $phone, string $ip): string
    {
        $token = bin2hex(random_bytes(16));
        $this->db->prepare('INSERT INTO diag_attempts (token, grade, lang, name, phone, created_at, ip) VALUES (?,?,?,?,?,?,?)')
            ->execute([$token, $grade, $lang, $name, $phone, date('Y-m-d H:i:s'), $ip]);

        return $token;
    }

    public function find(string $token): ?array
    {
        $st = $this->db->prepare('SELECT * FROM diag_attempts WHERE token = ?');
        $st->execute([$token]);

        return self::decode($st->fetch(PDO::FETCH_ASSOC) ?: null);
    }

    public function get(int $id): ?array
    {
        $st = $this->db->prepare('SELECT * FROM diag_attempts WHERE id = ?');
        $st->execute([$id]);

        return self::decode($st->fetch(PDO::FETCH_ASSOC) ?: null);
    }

    private static function decode(?array $a): ?array
    {
        if ($a) {
            $a['answers'] = json_decode((string) $a['answers'], true) ?: [];
            $a['topics'] = json_decode((string) $a['topics'], true) ?: [];
        }

        return $a;
    }

    /** Проверяет ответы и сохраняет результат. $given — [id вопроса => ответ]. */
    public function grade(array $test, array $given, array $overrides = []): array
    {
        $answers = [];
        $topics = [];
        $score = 0.0;
        $max = 0.0;
        foreach ($test['questions'] as $q) {
            $g = $given[$q['id']] ?? null;
            $g = is_scalar($g) ? mb_substr(trim((string) $g), 0, 200) : null;
            $auto = Checker::score($q['check'], $g);
            $frac = isset($overrides[$q['id']]) ? (float) $overrides[$q['id']] : $auto;
            $pts = round($q['points'] * $frac, 2);
            $answers[$q['id']] = ['given' => $g, 'auto' => $auto, 'score' => $pts, 'max' => $q['points']] + (isset($overrides[$q['id']]) ? ['manual' => true] : []);
            $topics[$q['topic']][0] = ($topics[$q['topic']][0] ?? 0) + $pts;
            $topics[$q['topic']][1] = ($topics[$q['topic']][1] ?? 0) + $q['points'];
            $score += $pts;
            $max += $q['points'];
        }

        return ['answers' => $answers, 'topics' => $topics, 'score' => round($score, 2), 'max' => $max, 'percent' => $max ? round($score / $max * 100, 1) : 0];
    }

    public function finish(array $attempt, array $result, ?int $leadId, ?string $at = null): void
    {
        $this->db->prepare('UPDATE diag_attempts SET finished_at = ?, score = ?, max_score = ?, percent = ?, answers = ?, topics = ?, lead_id = COALESCE(?, lead_id) WHERE id = ?')
            ->execute([$at ?? $attempt['finished_at'] ?? date('Y-m-d H:i:s'), $result['score'], $result['max'], $result['percent'],
                json_encode($result['answers'], JSON_UNESCAPED_UNICODE), json_encode($result['topics'], JSON_UNESCAPED_UNICODE), $leadId, $attempt['id']]);
    }

    /** Ручная правка балла за вопрос (доля 0…1) учителем; итог пересчитывается. */
    public function override(array $attempt, string $qid, ?float $frac): void
    {
        $test = $this->test((int) $attempt['grade']);
        $given = array_map(fn ($a) => $a['given'], $attempt['answers']);
        $ov = [];
        foreach ($attempt['answers'] as $id => $a) {
            if (!empty($a['manual'])) {
                $ov[$id] = $a['score'] / max(1, $a['max']);
            }
        }
        if ($frac === null) {
            unset($ov[$qid]);
        } else {
            $ov[$qid] = max(0, min(1, $frac));
        }
        $this->finish($attempt, $this->grade($test, $given, $ov), null);
    }

    public function recentFromIp(string $ip, int $minutes): int
    {
        $st = $this->db->prepare('SELECT COUNT(*) FROM diag_attempts WHERE ip = ? AND created_at >= ?');
        $st->execute([$ip, date('Y-m-d H:i:s', time() - $minutes * 60)]);

        return (int) $st->fetchColumn();
    }

    public function list(array $f = [], int $limit = 300): array
    {
        [$where, $args] = $this->where($f);
        $st = $this->db->prepare('SELECT d.*, l.status lead_status FROM diag_attempts d LEFT JOIN leads l ON l.id = d.lead_id WHERE ' . $where . ' ORDER BY d.finished_at DESC LIMIT ' . $limit);
        $st->execute($args);

        return array_map(fn ($a) => self::decode($a), $st->fetchAll(PDO::FETCH_ASSOC));
    }

    private function where(array $f): array
    {
        $w = ['d.finished_at IS NOT NULL'];
        $args = [];
        if (!empty($f['grade'])) {
            $w[] = 'd.grade = ?';
            $args[] = (int) $f['grade'];
        }
        if (!empty($f['lang'])) {
            $w[] = 'd.lang = ?';
            $args[] = $f['lang'];
        }
        if (empty($f['demo'])) {
            $w[] = 'd.demo = 0';
        }

        return [implode(' AND ', $w), $args];
    }

    public function demoCount(): int
    {
        return (int) $this->db->query('SELECT COUNT(*) FROM diag_attempts WHERE demo = 1')->fetchColumn();
    }

    public function deleteDemo(): int
    {
        return $this->db->exec('DELETE FROM diag_attempts WHERE demo = 1');
    }

    public function delete(int $id): void
    {
        $this->db->prepare('DELETE FROM diag_attempts WHERE id = ?')->execute([$id]);
    }

    /** Сводка для админки: по классам, темам, трудным заданиям, распределение и динамика. */
    public function stats(array $f): array
    {
        $rows = $this->list($f, 100000);
        $data = $this->data();
        $qs = [];
        foreach ($data['tests'] as $t) {
            foreach ($t['questions'] as $i => $q) {
                $qs[$q['id']] = $q + ['grade' => $t['grade'], 'n' => $i + 1];
            }
        }
        $byGrade = [];
        $heat = [];
        $perQ = [];
        $hist = array_fill(0, 10, 0);
        $days = [];
        $dur = [];
        foreach ($rows as $r) {
            $g = (int) $r['grade'];
            $byGrade[$g][] = (float) $r['percent'];
            $hist[min(9, (int) floor($r['percent'] / 10))]++;
            $days[substr($r['finished_at'], 0, 10)] = ($days[substr($r['finished_at'], 0, 10)] ?? 0) + 1;
            $dur[] = max(0, strtotime($r['finished_at']) - strtotime($r['created_at']));
            foreach ($r['topics'] as $topic => [$s, $m]) {
                $heat[$g][$topic][0] = ($heat[$g][$topic][0] ?? 0) + $s;
                $heat[$g][$topic][1] = ($heat[$g][$topic][1] ?? 0) + $m;
            }
            foreach ($r['answers'] as $qid => $a) {
                $perQ[$qid]['n'] = ($perQ[$qid]['n'] ?? 0) + 1;
                $perQ[$qid]['s'] = ($perQ[$qid]['s'] ?? 0) + $a['score'] / max(1, $a['max']);
                if ($a['score'] < $a['max'] && ($a['given'] ?? '') !== '' && $a['given'] !== null) {
                    $key = mb_strtolower(trim((string) $a['given']));
                    $perQ[$qid]['wrong'][$key] = ($perQ[$qid]['wrong'][$key] ?? 0) + 1;
                }
                if (($a['given'] ?? '') === '' || $a['given'] === null) {
                    $perQ[$qid]['empty'] = ($perQ[$qid]['empty'] ?? 0) + 1;
                }
            }
        }
        ksort($byGrade);
        ksort($heat);
        $hard = [];
        foreach ($perQ as $qid => $p) {
            if (!isset($qs[$qid]) || $p['n'] < 2) {
                continue;
            }
            $p['wrong'] ??= [];
            arsort($p['wrong']);
            $top = $p['wrong'] ? array_key_first($p['wrong']) : null;
            $q = $qs[$qid];
            if ($top !== null && $q['check']['type'] === 'choice') {
                $top = Content::localize($q['check']['options'][(int) $top] ?? [], 'ru');
            }
            $hard[] = ['id' => $qid, 'grade' => $q['grade'], 'n' => $q['n'], 'text' => $q['text']['ru'], 'topic' => $q['topic'],
                'rate' => $p['s'] / $p['n'] * 100, 'count' => $p['n'], 'wrong' => $top, 'wrong_n' => $top !== null ? reset($p['wrong']) : 0,
                'empty' => $p['empty'] ?? 0];
        }
        usort($hard, fn ($a, $b) => [$a['rate'], -$a['count']] <=> [$b['rate'], -$b['count']]);
        // Динамика за 30 дней
        $series = [];
        for ($i = 29; $i >= 0; $i--) {
            $d = date('Y-m-d', strtotime("-$i days"));
            $series[$d] = $days[$d] ?? 0;
        }
        $leads = array_filter($rows, fn ($r) => $r['lead_id']);
        $won = array_filter($rows, fn ($r) => ($r['lead_status'] ?? '') === 'done');

        return [
            'total' => count($rows),
            'avg' => $rows ? array_sum(array_column($rows, 'percent')) / count($rows) : null,
            'median_minutes' => $dur ? (function ($d) { sort($d); return $d[intdiv(count($d), 2)] / 60; })($dur) : null,
            'leads' => count($leads),
            'won' => count($won),
            'by_grade' => array_map(fn ($p) => ['n' => count($p), 'avg' => array_sum($p) / count($p), 'levels' => array_count_values(array_map([self::class, 'level'], $p))], $byGrade),
            'heat' => array_map(fn ($t) => array_map(fn ($v) => $v[1] ? $v[0] / $v[1] * 100 : null, $t), $heat),
            'hard' => array_slice($hard, 0, 10),
            'hist' => $hist,
            'series' => $series,
            'levels' => array_count_values(array_map(fn ($r) => self::level((float) $r['percent']), $rows)),
        ];
    }

    /** Примеры прохождений, чтобы статистика была не пустой. Помечены demo = 1. */
    public function seedDemo(int $count = 20): void
    {
        $names = ['Алихан С.', 'Аружан Т.', 'Данияр К.', 'Амина Б.', 'Нурислам Е.', 'Айсулу М.', 'Тимур А.', 'Жанель Р.', 'Ерасыл Н.',
            'Камила Ж.', 'Арсен Д.', 'Томирис О.', 'Ислам Г.', 'Медина С.', 'Рауан И.', 'Дильназ У.', 'Санжар П.', 'Асель Х.'];
        mt_srand(20261031);
        $grades = $this->grades();
        for ($i = 0; $i < $count; $i++) {
            $grade = $grades[$i % count($grades)];
            $test = $this->test($grade);
            $ability = [0.2, 0.45, 0.6, 0.75, 0.9][mt_rand(0, 4)] + (mt_rand(-10, 10) / 100);
            $given = [];
            foreach ($test['questions'] as $n => $q) {
                $difficulty = 0.25 + 0.5 * $n / max(1, count($test['questions']) - 1);
                $p = 1 / (1 + exp(-8 * ($ability - $difficulty)));
                $roll = mt_rand() / mt_getrandmax();
                if ($q['check']['type'] === 'choice') {
                    $wrong = array_values(array_diff(array_keys($q['check']['options']), [$q['check']['answer']]));
                    $given[$q['id']] = $roll < $p ? $q['check']['answer'] : ($roll > 0.97 ? null : $wrong[mt_rand(0, count($wrong) - 1)]);
                } elseif ($roll < $p) {
                    $given[$q['id']] = self::sampleRight($q);
                } elseif ($roll < 0.93) {
                    $given[$q['id']] = $q['mistakes'][mt_rand(0, count($q['mistakes'] ?? [1]) - 1)] ?? '';
                } else {
                    $given[$q['id']] = '';
                }
            }
            $lang = ['ru', 'ru', 'kz'][mt_rand(0, 2)];
            $start = strtotime('today -' . mt_rand(1, 26) . ' days') + mt_rand(10 * 3600, 20 * 3600);
            $this->db->prepare('INSERT INTO diag_attempts (token, grade, lang, name, phone, created_at, demo) VALUES (?,?,?,?,?,?,1)')
                ->execute([bin2hex(random_bytes(16)), $grade, $lang, $names[$i % count($names)], sprintf('+7 700 000 00 %02d', $i + 1), date('Y-m-d H:i:s', $start)]);
            $attempt = ['id' => (int) $this->db->lastInsertId()];
            $this->finish($attempt, $this->grade($test, $given), null, date('Y-m-d H:i:s', $start + mt_rand(14, 42) * 60));
        }
    }

    /** Верный ответ в том виде, как его мог бы написать ученик (для демо). */
    private static function sampleRight(array $q): string
    {
        $c = $q['check'];
        $fmt = fn ($v) => rtrim(rtrim(number_format((float) $v, 2, ',', ''), '0'), ',');

        return match ($c['type']) {
            'number' => $fmt($c['answer'][0]),
            'seq' => implode('; ', array_map($fmt, $c['answer'])),
            'pairs' => implode(';', array_map(fn ($p) => '(' . $fmt($p[0]) . ';' . $fmt($p[1]) . ')', $c['answer'])),
            'expr' => $c['answer'],
            'ineq' => $c['answer'],
            'letters' => strtoupper(implode(', ', $c['answer'])),
            'keywords', 'parts' => match ($q['id']) {
                'g7q1' => '1440; 3:4:5', 'g8q13' => '65°, остроугольный', 'g10q4' => 'треугольник', 'g11q1' => 'R; R',
                'g11q2' => 'чётная', 'g11q3' => 'сдвиг вверх на 2', 'g11q4' => 'x = 0 и y = 0', default => '',
            },
            default => '',
        };
    }
}
