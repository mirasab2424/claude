<?php

namespace Kost;

/** Вход учителей: логин и пароль из таблицы teachers, сессия, CSRF, 5 неудачных попыток за 15 минут с IP. */
final class Auth
{
    private ?array $user = null;

    public function __construct(private Db $db, private string $storage)
    {
    }

    public function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        session_name('kosttest');
        session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax', 'secure' => App::isHttps()]);
        session_start();
        if (empty($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(16));
        }
    }

    public function user(): ?array
    {
        if ($this->user) {
            return $this->user;
        }
        if (empty($_COOKIE['kosttest']) && session_status() !== PHP_SESSION_ACTIVE) {
            return null;
        }
        $this->start();
        if (empty($_SESSION['teacher'])) {
            return null;
        }
        $u = $this->db->one('SELECT * FROM teachers WHERE id = ?', [(int) $_SESSION['teacher']]);
        // После смены пароля старые сессии перестают действовать
        if (!$u || substr($u['password'], -12) !== ($_SESSION['pw'] ?? '')) {
            return null;
        }
        $u['is_admin'] = (int) $u['is_admin'];

        return $this->user = $u;
    }

    public function csrf(): string
    {
        $this->start();

        return $_SESSION['csrf'];
    }

    public function validCsrf(?string $token): bool
    {
        $this->start();

        return is_string($token) && hash_equals($_SESSION['csrf'], $token);
    }

    public function throttled(string $ip): bool
    {
        return count($this->attempts()[$ip] ?? []) >= 5;
    }

    public function login(string $login, string $password, string $ip): bool
    {
        if ($this->throttled($ip)) {
            return false;
        }
        $u = $this->db->one('SELECT * FROM teachers WHERE login = ?', [mb_strtolower(trim($login))]);
        if (!$u || !password_verify($password, $u['password'])) {
            $all = $this->attempts();
            $all[$ip][] = time();
            file_put_contents($this->storage . '/throttle.json', json_encode($all), LOCK_EX);

            return false;
        }
        $this->start();
        session_regenerate_id(true);
        $_SESSION['teacher'] = (int) $u['id'];
        $_SESSION['pw'] = substr($u['password'], -12);

        return true;
    }

    public function logout(): void
    {
        $this->start();
        $_SESSION = [];
        session_destroy();
    }

    public function setPassword(int $id, string $password): void
    {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $this->db->run('UPDATE teachers SET password = ? WHERE id = ?', [$hash, $id]);
        if (($this->user['id'] ?? null) === $id || (int) ($_SESSION['teacher'] ?? 0) === $id) {
            $_SESSION['pw'] = substr($hash, -12);
            $this->user = null;
        }
    }

    private function attempts(): array
    {
        $f = $this->storage . '/throttle.json';
        $all = is_file($f) ? (json_decode((string) file_get_contents($f), true) ?: []) : [];
        foreach ($all as $ip => $times) {
            $all[$ip] = array_values(array_filter($times, fn ($t) => $t > time() - 900));
            if (!$all[$ip]) {
                unset($all[$ip]);
            }
        }

        return $all;
    }
}
