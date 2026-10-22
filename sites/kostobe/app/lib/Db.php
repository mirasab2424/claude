<?php

namespace Kost;

use PDO;

/** SQLite в storage/tests.sqlite. Схема создаётся при первом запросе, туда же загружаются примеры тестов. */
final class Db
{
    public PDO $pdo;

    public function __construct(string $file, private string $samplesFile, private string $adminHash)
    {
        $new = !is_file($file);
        $this->pdo = new PDO('sqlite:' . $file, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $this->pdo->exec('PRAGMA foreign_keys = ON');
        $this->pdo->exec('PRAGMA busy_timeout = 5000');
        $this->migrate();
        if ($new) {
            $this->seed();
        }
    }

    private function migrate(): void
    {
        $this->pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS teachers (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL, login TEXT NOT NULL UNIQUE, password TEXT NOT NULL,
            is_admin INTEGER NOT NULL DEFAULT 0, created_at TEXT NOT NULL
        );
        CREATE TABLE IF NOT EXISTS subjects (
            id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, position INTEGER NOT NULL DEFAULT 0
        );
        CREATE TABLE IF NOT EXISTS tests (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            subject_id INTEGER REFERENCES subjects(id) ON DELETE SET NULL,
            teacher_id INTEGER REFERENCES teachers(id) ON DELETE SET NULL,
            title TEXT NOT NULL, description TEXT NOT NULL DEFAULT '{}', langs TEXT NOT NULL DEFAULT '["ru"]',
            grades TEXT NOT NULL DEFAULT '',
            time_limit INTEGER NOT NULL DEFAULT 0, shuffle_questions INTEGER NOT NULL DEFAULT 0,
            shuffle_options INTEGER NOT NULL DEFAULT 1, show_answers INTEGER NOT NULL DEFAULT 1,
            published INTEGER NOT NULL DEFAULT 0, created_at TEXT NOT NULL, updated_at TEXT NOT NULL
        );
        CREATE TABLE IF NOT EXISTS questions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            test_id INTEGER NOT NULL REFERENCES tests(id) ON DELETE CASCADE,
            position INTEGER NOT NULL, type TEXT NOT NULL, text TEXT NOT NULL,
            points REAL NOT NULL DEFAULT 1, data TEXT NOT NULL DEFAULT '{}'
        );
        CREATE INDEX IF NOT EXISTS questions_test ON questions(test_id, position);
        CREATE TABLE IF NOT EXISTS attempts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            token TEXT NOT NULL UNIQUE,
            test_id INTEGER NOT NULL REFERENCES tests(id) ON DELETE CASCADE,
            lang TEXT NOT NULL, full_name TEXT NOT NULL, class_name TEXT NOT NULL, subject TEXT NOT NULL,
            started_at TEXT NOT NULL, finished_at TEXT, score REAL, max_score REAL, percent REAL, mark INTEGER,
            questions TEXT NOT NULL, answers TEXT, ip TEXT
        );
        CREATE INDEX IF NOT EXISTS attempts_test ON attempts(test_id, finished_at);
        CREATE TABLE IF NOT EXISTS settings (key TEXT PRIMARY KEY, value TEXT NOT NULL);
        SQL);
    }

    private function seed(): void
    {
        $now = date('Y-m-d H:i:s');
        $this->pdo->prepare('INSERT INTO teachers (name, login, password, is_admin, created_at) VALUES (?,?,?,1,?)')
            ->execute(['Администратор', 'admin', $this->adminHash, $now]);
        if (!is_file($this->samplesFile)) {
            return;
        }
        $data = json_decode((string) file_get_contents($this->samplesFile), true, 512, JSON_THROW_ON_ERROR);
        $tests = new Tests($this);
        $this->pdo->beginTransaction();
        $subjectIds = [];
        foreach ($data['subjects'] as $i => $s) {
            $this->pdo->prepare('INSERT INTO subjects (name, position) VALUES (?, ?)')->execute([self::json($s['name']), $i]);
            $subjectIds[$s['key']] = (int) $this->pdo->lastInsertId();
        }
        foreach ($data['tests'] as $t) {
            $t['subject_id'] = $subjectIds[$t['subject']] ?? null;
            $t['teacher_id'] = 1;
            $tests->save(null, $t, $t['questions']);
        }
        $this->pdo->commit();
    }

    public static function json(mixed $v): string
    {
        return json_encode($v, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    public function all(string $sql, array $args = []): array
    {
        $st = $this->pdo->prepare($sql);
        $st->execute($args);

        return $st->fetchAll();
    }

    public function one(string $sql, array $args = []): ?array
    {
        $st = $this->pdo->prepare($sql);
        $st->execute($args);

        return $st->fetch() ?: null;
    }

    public function value(string $sql, array $args = []): mixed
    {
        $st = $this->pdo->prepare($sql);
        $st->execute($args);

        return $st->fetchColumn();
    }

    public function run(string $sql, array $args = []): int
    {
        $st = $this->pdo->prepare($sql);
        $st->execute($args);

        return $st->rowCount();
    }

    public function setting(string $key, mixed $default = null): mixed
    {
        $v = $this->value('SELECT value FROM settings WHERE key = ?', [$key]);

        return $v === false ? $default : json_decode((string) $v, true);
    }

    public function setSetting(string $key, mixed $value): void
    {
        $this->run('INSERT INTO settings (key, value) VALUES (?, ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value', [$key, self::json($value)]);
    }
}
