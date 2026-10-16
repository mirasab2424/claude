<?php

namespace Smart;

use PDO;

/** Заявки с сайта в SQLite (storage/leads.sqlite). */
final class Leads
{
    public const STATUSES = ['new' => 'Новая', 'work' => 'В работе', 'done' => 'Записан', 'rejected' => 'Отказ'];

    private ?PDO $pdo = null;

    public function __construct(private string $file)
    {
    }

    public function db(): PDO
    {
        if ($this->pdo) {
            return $this->pdo;
        }
        $this->pdo = new PDO('sqlite:' . $this->file, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS leads (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            created_at TEXT NOT NULL,
            name TEXT NOT NULL, phone TEXT NOT NULL, child TEXT, course TEXT, branch TEXT,
            lang TEXT, page TEXT, ip TEXT, status TEXT NOT NULL DEFAULT \'new\', note TEXT
        )');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS leads_created ON leads(created_at)');

        return $this->pdo;
    }

    public function add(array $l): int
    {
        $st = $this->db()->prepare('INSERT INTO leads (created_at, name, phone, child, course, branch, lang, page, ip) VALUES (?,?,?,?,?,?,?,?,?)');
        $st->execute([date('Y-m-d H:i:s'), $l['name'], $l['phone'], $l['child'], $l['course'], $l['branch'], $l['lang'], $l['page'], $l['ip']]);

        return (int) $this->db()->lastInsertId();
    }

    public function recentFromIp(string $ip, int $minutes): int
    {
        $st = $this->db()->prepare('SELECT COUNT(*) FROM leads WHERE ip = ? AND created_at >= ?');
        $st->execute([$ip, date('Y-m-d H:i:s', time() - $minutes * 60)]);

        return (int) $st->fetchColumn();
    }

    public function list(?string $status = null, int $limit = 500): array
    {
        if ($status && isset(self::STATUSES[$status])) {
            $st = $this->db()->prepare('SELECT * FROM leads WHERE status = ? ORDER BY id DESC LIMIT ' . $limit);
            $st->execute([$status]);
        } else {
            $st = $this->db()->query('SELECT * FROM leads ORDER BY id DESC LIMIT ' . $limit);
        }

        return $st->fetchAll();
    }

    public function counts(): array
    {
        $c = array_fill_keys(array_keys(self::STATUSES), 0);
        foreach ($this->db()->query('SELECT status, COUNT(*) n FROM leads GROUP BY status') as $r) {
            $c[$r['status']] = (int) $r['n'];
        }
        $c['all'] = array_sum($c);
        $c['week'] = (int) $this->db()->query("SELECT COUNT(*) FROM leads WHERE created_at >= '" . date('Y-m-d H:i:s', time() - 7 * 86400) . "'")->fetchColumn();

        return $c;
    }

    public function update(int $id, string $status, string $note): void
    {
        if (!isset(self::STATUSES[$status])) {
            return;
        }
        $this->db()->prepare('UPDATE leads SET status = ?, note = ? WHERE id = ?')->execute([$status, mb_substr($note, 0, 2000), $id]);
    }

    public function delete(int $id): void
    {
        $this->db()->prepare('DELETE FROM leads WHERE id = ?')->execute([$id]);
    }
}
