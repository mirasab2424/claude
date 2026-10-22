<?php

namespace Kost;

use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFilter;
use Twig\TwigFunction;

/** Фронт-контроллер: прохождение тестов на трёх языках (/, /kz/, /en/) и админка учителя (/admin). */
final class App
{
    public Db $db;
    public Tests $tests;
    public Auth $auth;
    public string $storage;
    public string $initialHash;

    public function __construct(public string $root, public string $public)
    {
        $this->storage = $root . '/storage';
        if (!is_dir($this->storage . '/cache')) {
            mkdir($this->storage . '/cache', 0775, true);
        }
        $config = require $root . '/config.php';
        $this->initialHash = $config['admin_password_hash'];
        $this->db = new Db($this->storage . '/tests.sqlite', $root . '/content/samples.json', $config['admin_password_hash']);
        $this->tests = new Tests($this->db);
        $this->auth = new Auth($this->db, $this->storage);
    }

    public static function isHttps(): bool
    {
        return ($_SERVER['HTTPS'] ?? '') === 'on' || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https' || ($_SERVER['SERVER_PORT'] ?? '') === '443';
    }

    public static function ip(): string
    {
        return (string) ($_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? '');
    }

    public static function url(string $path = '', string $lang = 'ru'): string
    {
        return ($lang === 'ru' ? '' : '/' . $lang) . '/' . ltrim($path, '/');
    }

    public function redirect(string $to, int $code = 302): never
    {
        header('Location: ' . $to, true, $code);
        exit;
    }

    public function marks(): array
    {
        return array_replace(Grader::MARKS, (array) $this->db->setting('marks', []));
    }

    public function siteName(): array
    {
        return array_replace(['ru' => 'Тесты Kostobe', 'kz' => 'Kostobe тесттері', 'en' => 'Kostobe Tests'], array_filter((array) $this->db->setting('site_name', [])));
    }

    public function run(): void
    {
        $path = '/' . trim(rawurldecode((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH)), '/');
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: same-origin');

        if ($path === '/admin' || str_starts_with($path, '/admin/')) {
            (new Admin($this))->handle(substr($path, 6) ?: '/', $method);

            return;
        }

        $parts = $path === '/' ? [] : explode('/', ltrim($path, '/'));
        $lang = 'ru';
        if ($parts && in_array($parts[0], ['kz', 'en'], true)) {
            $lang = array_shift($parts);
        }
        $site = new Site($this, $lang);
        match (true) {
            $parts === [] => $site->home(),
            count($parts) === 2 && $parts[0] === 't' && ctype_digit($parts[1]) => $site->start((int) $parts[1], $method),
            count($parts) === 2 && $parts[0] === 'a' && ctype_xdigit($parts[1]) => $site->take($parts[1], $method),
            count($parts) === 2 && $parts[0] === 'r' && ctype_xdigit($parts[1]) => $site->result($parts[1]),
            default => $site->notFound(),
        };
    }

    public function twig(string $dir, string $lang): Environment
    {
        $twig = new Environment(new FilesystemLoader($this->root . '/templates/' . $dir), [
            'cache' => $this->storage . '/cache/twig',
            'auto_reload' => true,
        ]);
        $strings = self::strings($this->root . '/lang', $lang);
        $twig->addFunction(new TwigFunction('__', function (string $k, array $a = []) use ($strings) {
            $s = $strings[$k] ?? $k;

            return $a ? sprintf($s, ...$a) : $s;
        }, ['is_variadic' => true]));
        $twig->addFunction(new TwigFunction('url', fn (string $p = '', ?string $l = null) => self::url($p, $l ?? $lang)));
        $twig->addFunction(new TwigFunction('asset', function (string $p) {
            $f = $this->public . '/' . ltrim($p, '/');

            return '/' . ltrim($p, '/') . (is_file($f) ? '?v=' . filemtime($f) : '');
        }));
        $twig->addFilter(new TwigFilter('l', fn ($v, ?string $l = null) => L::t($v, $l ?? $lang)));
        $twig->addFilter(new TwigFilter('num', fn ($v) => rtrim(rtrim(number_format((float) $v, 2, ',', ' '), '0'), ',')));
        $twig->addGlobal('lang', $lang);
        $twig->addGlobal('html_lang', L::HTML[$lang]);
        $twig->addGlobal('lang_names', L::NAMES);
        $twig->addGlobal('lang_short', L::SHORT);
        $twig->addGlobal('site_name', L::t($this->siteName(), $lang));

        return $twig;
    }

    public static function strings(string $dir, string $lang): array
    {
        $ru = require $dir . '/ru.php';

        return $lang === 'ru' ? $ru : array_replace($ru, require $dir . '/' . $lang . '.php');
    }
}
