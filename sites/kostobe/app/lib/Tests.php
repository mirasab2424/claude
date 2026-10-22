<?php

namespace Kost;

/** Тесты и вопросы: чтение, сохранение из редактора, проверка присланных данных. */
final class Tests
{
    public const TYPES = [
        'single' => 'Один ответ (A, B, C…)',
        'multiple' => 'Несколько ответов',
        'truefalse' => 'Верно / неверно',
        'text' => 'Ответ текстом',
        'number' => 'Ответ числом',
        'order' => 'Расставить по порядку',
        'match' => 'Найти пары',
    ];

    public function __construct(private Db $db)
    {
    }

    public static function decode(array $t): array
    {
        foreach (['title', 'description', 'langs'] as $k) {
            $t[$k] = json_decode((string) $t[$k], true) ?: [];
        }
        if (isset($t['subject_name'])) {
            $t['subject_name'] = json_decode((string) $t['subject_name'], true) ?: [];
        }
        foreach (['time_limit', 'shuffle_questions', 'shuffle_options', 'show_answers', 'published'] as $k) {
            $t[$k] = (int) $t[$k];
        }

        return $t;
    }

    public function find(int $id): ?array
    {
        $t = $this->db->one('SELECT t.*, s.name subject_name FROM tests t LEFT JOIN subjects s ON s.id = t.subject_id WHERE t.id = ?', [$id]);

        return $t ? self::decode($t) : null;
    }

    public function questions(int $testId): array
    {
        return array_map(fn ($q) => [
            'id' => (int) $q['id'],
            'type' => $q['type'],
            'text' => json_decode($q['text'], true) ?: [],
            'points' => (float) $q['points'],
            'data' => json_decode($q['data'], true) ?: [],
        ], $this->db->all('SELECT * FROM questions WHERE test_id = ? ORDER BY position, id', [$testId]));
    }

    /** Список тестов со счётчиками. $teacherId = null — все тесты. */
    public function list(?int $teacherId = null, bool $publishedOnly = false): array
    {
        $where = [];
        $args = [];
        if ($teacherId !== null) {
            $where[] = 't.teacher_id = ?';
            $args[] = $teacherId;
        }
        if ($publishedOnly) {
            $where[] = 't.published = 1';
        }
        $rows = $this->db->all('SELECT t.*, s.name subject_name, s.position subject_pos, tc.name teacher_name,
                (SELECT COUNT(*) FROM questions q WHERE q.test_id = t.id) q_count,
                (SELECT COUNT(*) FROM attempts a WHERE a.test_id = t.id AND a.finished_at IS NOT NULL) a_count,
                (SELECT AVG(percent) FROM attempts a WHERE a.test_id = t.id AND a.finished_at IS NOT NULL) a_avg
            FROM tests t LEFT JOIN subjects s ON s.id = t.subject_id LEFT JOIN teachers tc ON tc.id = t.teacher_id'
            . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY s.position, t.id DESC', $args);

        return array_map(fn ($t) => self::decode($t), $rows);
    }

    /** Сохраняет тест целиком (вопросы заменяются). Возвращает id. */
    public function save(?int $id, array $meta, array $questions): int
    {
        $now = date('Y-m-d H:i:s');
        $langs = array_values(array_intersect(L::LANGS, (array) ($meta['langs'] ?? ['ru']))) ?: ['ru'];
        $row = [
            $meta['subject_id'] ?? null,
            Db::json(L::clean($meta['title'] ?? [])),
            Db::json(L::clean($meta['description'] ?? [])),
            Db::json($langs),
            mb_substr(trim((string) ($meta['grades'] ?? '')), 0, 60),
            max(0, min(600, (int) ($meta['time_limit'] ?? 0))),
            !empty($meta['shuffle_questions']) ? 1 : 0,
            !empty($meta['shuffle_options']) ? 1 : 0,
            !empty($meta['show_answers']) ? 1 : 0,
            !empty($meta['published']) ? 1 : 0,
            $now,
        ];
        $own = !$this->db->pdo->inTransaction();
        if ($own) {
            $this->db->pdo->beginTransaction();
        }
        if ($id) {
            $this->db->run('UPDATE tests SET subject_id=?, title=?, description=?, langs=?, grades=?, time_limit=?, shuffle_questions=?,
                shuffle_options=?, show_answers=?, published=?, updated_at=? WHERE id=?', [...$row, $id]);
        } else {
            $this->db->run('INSERT INTO tests (subject_id, title, description, langs, grades, time_limit, shuffle_questions, shuffle_options,
                show_answers, published, updated_at, teacher_id, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)', [...$row, $meta['teacher_id'] ?? null, $now]);
            $id = (int) $this->db->pdo->lastInsertId();
        }
        // id вопросов сохраняются при правке — статистика по вопросам не теряется
        $existing = array_map('intval', array_column($this->db->all('SELECT id FROM questions WHERE test_id = ?', [$id]), 'id'));
        $keep = [];
        foreach (array_values($questions) as $i => $raw) {
            $q = self::normalize($raw)['question'];
            $qid = (int) ($raw['id'] ?? 0);
            if ($qid && in_array($qid, $existing, true) && !in_array($qid, $keep, true)) {
                $this->db->run('UPDATE questions SET position=?, type=?, text=?, points=?, data=? WHERE id=?',
                    [$i, $q['type'], Db::json($q['text']), $q['points'], Db::json($q['data']), $qid]);
                $keep[] = $qid;
            } else {
                $this->db->run('INSERT INTO questions (test_id, position, type, text, points, data) VALUES (?,?,?,?,?,?)',
                    [$id, $i, $q['type'], Db::json($q['text']), $q['points'], Db::json($q['data'])]);
            }
        }
        foreach (array_diff($existing, $keep) as $qid) {
            $this->db->run('DELETE FROM questions WHERE id = ?', [$qid]);
        }
        if ($own) {
            $this->db->pdo->commit();
        }

        return $id;
    }

    private static function id(mixed $v): string
    {
        $v = (string) $v;

        return preg_match('~^[a-z0-9]{1,16}$~', $v) ? $v : substr(bin2hex(random_bytes(4)), 0, 8);
    }

    /**
     * Приводит вопрос из редактора к сохраняемому виду и возвращает ошибки (по-русски, для учителя).
     * @return array{question: array, errors: string[]}
     */
    public static function normalize(array $q): array
    {
        $type = isset(self::TYPES[$q['type'] ?? '']) ? $q['type'] : 'single';
        $d = is_array($q['data'] ?? null) ? $q['data'] : [];
        $errors = [];
        $text = L::clean($q['text'] ?? []);
        if (!array_filter($text)) {
            $errors[] = 'нет текста вопроса';
        }
        $data = ['explain' => L::clean($d['explain'] ?? [])];
        $ids = [];
        $uniq = function (mixed $v) use (&$ids): string {
            $id = self::id($v);
            while (isset($ids[$id])) {
                $id = substr(bin2hex(random_bytes(4)), 0, 8);
            }
            $ids[$id] = true;

            return $id;
        };

        switch ($type) {
            case 'single':
            case 'multiple':
                $opts = [];
                foreach ((array) ($d['options'] ?? []) as $o) {
                    $t = L::clean($o['text'] ?? []);
                    if (array_filter($t)) {
                        $opts[] = ['id' => $uniq($o['id'] ?? ''), 'text' => $t, 'correct' => !empty($o['correct'])];
                    }
                }
                $right = count(array_filter($opts, fn ($o) => $o['correct']));
                if (count($opts) < 2) {
                    $errors[] = 'нужно минимум 2 варианта';
                }
                if ($right === 0) {
                    $errors[] = 'не отмечен правильный ответ';
                }
                if ($type === 'single' && $right > 1) {
                    $seen = false;
                    foreach ($opts as &$o) {
                        $o['correct'] = $o['correct'] && !$seen;
                        $seen = $seen || $o['correct'];
                    }
                    unset($o);
                    $errors[] = 'в вопросе с одним ответом отмечено несколько правильных — оставлен первый';
                }
                $data['options'] = $opts;
                if ($type === 'multiple') {
                    $data['partial'] = !empty($d['partial']);
                }
                break;
            case 'truefalse':
                $data['answer'] = !empty($d['answer']);
                break;
            case 'text':
                $data['answers'] = [];
                foreach (L::LANGS as $l) {
                    $list = is_array($d['answers'][$l] ?? null) ? $d['answers'][$l] : preg_split('~\R~', (string) ($d['answers'][$l] ?? ''));
                    $data['answers'][$l] = array_values(array_unique(array_filter(array_map(fn ($s) => trim((string) $s), $list), 'strlen')));
                }
                if (!array_filter($data['answers'])) {
                    $errors[] = 'не указан правильный ответ';
                }
                break;
            case 'number':
                $a = Grader::parseNumber($d['answer'] ?? '');
                if ($a === null) {
                    $errors[] = 'правильный ответ должен быть числом';
                }
                $data['answer'] = $a ?? 0;
                $data['tolerance'] = abs(Grader::parseNumber($d['tolerance'] ?? 0) ?? 0);
                $data['unit'] = L::clean($d['unit'] ?? []);
                break;
            case 'order':
                $data['items'] = [];
                foreach ((array) ($d['items'] ?? []) as $it) {
                    $t = L::clean($it['text'] ?? []);
                    if (array_filter($t)) {
                        $data['items'][] = ['id' => $uniq($it['id'] ?? ''), 'text' => $t];
                    }
                }
                if (count($data['items']) < 2) {
                    $errors[] = 'нужно минимум 2 пункта';
                }
                break;
            case 'match':
                $data['pairs'] = [];
                foreach ((array) ($d['pairs'] ?? []) as $p) {
                    $left = L::clean($p['left'] ?? []);
                    $right = L::clean($p['right'] ?? []);
                    if (array_filter($left) && array_filter($right)) {
                        $data['pairs'][] = ['id' => $uniq($p['id'] ?? ''), 'left' => $left, 'right' => $right];
                    }
                }
                if (count($data['pairs']) < 2) {
                    $errors[] = 'нужно минимум 2 пары';
                }
                break;
        }

        $points = (float) str_replace(',', '.', (string) ($q['points'] ?? 1));

        return [
            'question' => ['type' => $type, 'text' => $text, 'points' => $points > 0 ? min($points, 100) : 1, 'data' => $data],
            'errors' => $errors,
        ];
    }

    /** Какие тексты вопроса не переведены на язык (для подсказки учителю). */
    public static function missing(array $q, string $lang): bool
    {
        $need = [$q['text']];
        $d = $q['data'];
        foreach ($d['options'] ?? [] as $o) {
            $need[] = $o['text'];
        }
        foreach ($d['items'] ?? [] as $o) {
            $need[] = $o['text'];
        }
        foreach ($d['pairs'] ?? [] as $o) {
            $need[] = $o['left'];
            $need[] = $o['right'];
        }
        foreach ($need as $v) {
            if (!L::has($v, $lang)) {
                return true;
            }
        }

        return $q['type'] === 'text' && empty($d['answers'][$lang]);
    }
}
