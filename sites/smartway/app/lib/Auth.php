<?php

namespace Smart;

/**
 * Вход в админку: один пароль. Хеш начального пароля — в config.php, после смены — в storage/admin.json.
 * Сессия + CSRF-токен, не больше 5 неудачных попыток за 15 минут с одного IP.
 */
final class Auth
{
    public function __construct(private string $storage, private string $initialHash)
    {
    }

    public function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        session_name('smartadm');
        session_set_cookie_params(['lifetime' => 0, 'path' => '/admin', 'httponly' => true, 'samesite' => 'Lax', 'secure' => App::isHttps()]);
        session_start();
        if (empty($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(16));
        }
    }

    public function check(): bool
    {
        $this->start();

        return !empty($_SESSION['admin']) && ($_SESSION['pw'] ?? '') === substr($this->hash(), -12);
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

    public function hash(): string
    {
        $f = $this->storage . '/admin.json';
        if (is_file($f)) {
            $d = json_decode((string) file_get_contents($f), true);
            if (!empty($d['hash'])) {
                return $d['hash'];
            }
        }

        return $this->initialHash;
    }

    public function usesInitialPassword(): bool
    {
        return $this->hash() === $this->initialHash;
    }

    public function throttled(string $ip): bool
    {
        return count($this->attempts($ip)) >= 5;
    }

    public function login(string $password, string $ip): bool
    {
        if ($this->throttled($ip)) {
            return false;
        }
        if (!password_verify($password, $this->hash())) {
            $all = $this->allAttempts();
            $all[$ip][] = time();
            file_put_contents($this->storage . '/throttle.json', json_encode($all), LOCK_EX);

            return false;
        }
        $this->start();
        session_regenerate_id(true);
        $_SESSION['admin'] = true;
        $_SESSION['pw'] = substr($this->hash(), -12);

        return true;
    }

    public function logout(): void
    {
        $this->start();
        $_SESSION = [];
        session_destroy();
    }

    public function changePassword(string $new): void
    {
        $hash = password_hash($new, PASSWORD_DEFAULT);
        file_put_contents($this->storage . '/admin.json', json_encode(['hash' => $hash, 'changed_at' => date('c')]), LOCK_EX);
        $_SESSION['pw'] = substr($hash, -12);
    }

    private function allAttempts(): array
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

    private function attempts(string $ip): array
    {
        return $this->allAttempts()[$ip] ?? [];
    }
}
