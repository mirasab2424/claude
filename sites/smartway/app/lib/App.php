<?php

namespace Smart;

use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFilter;
use Twig\TwigFunction;

/** Фронт-контроллер: публичные страницы на трёх языках, приём заявок и админка. */
final class App
{
    /** Страницы сайта: адрес → шаблон. Курсы совпадают со slug в content.courses. */
    public const PAGES = ['index', 'english', 'ielts', 'math', 'nis', 'school', 'prodlenka', 'teachers', 'prices', 'about', 'contacts'];

    public Content $content;
    public Leads $leads;
    public Auth $auth;
    public string $storage;

    public function __construct(public string $root, public string $public)
    {
        $this->storage = $root . '/storage';
        foreach ([$this->storage, $this->storage . '/cache', $this->storage . '/backups'] as $d) {
            if (!is_dir($d)) {
                mkdir($d, 0775, true);
            }
        }
        $config = require $root . '/config.php';
        $this->content = new Content($root . '/content/default.json', $this->storage . '/content.json', $this->storage . '/backups');
        $this->leads = new Leads($this->storage . '/leads.sqlite');
        $this->auth = new Auth($this->storage, $config['admin_password_hash']);
    }

    public static function isHttps(): bool
    {
        return ($_SERVER['HTTPS'] ?? '') === 'on' || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https' || ($_SERVER['SERVER_PORT'] ?? '') === '443';
    }

    public static function ip(): string
    {
        return (string) ($_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? '');
    }

    public static function url(string $page = 'index', string $lang = 'ru'): string
    {
        $prefix = $lang === 'ru' ? '' : '/' . $lang;

        return $page === 'index' ? $prefix . '/' : $prefix . '/' . $page;
    }

    public function run(): void
    {
        $path = '/' . trim(rawurldecode((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH)), '/');
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

        // Старые адреса статической версии: /english.html → /english
        if (preg_match('~^/((?:kz|en)/)?([a-z0-9]+)\.html$~', $path, $m)) {
            $page = $m[2] === 'index' ? 'index' : $m[2];
            $this->redirect(self::url($page, rtrim($m[1] ?? '', '/') ?: 'ru'), 301);
        }

        if ($path === '/lead' && $method === 'POST') {
            $this->lead();

            return;
        }
        if ($path === '/admin' || str_starts_with($path, '/admin/')) {
            (new Admin($this))->handle(substr($path, 6) ?: '/', $method);

            return;
        }

        $parts = $path === '/' ? [] : explode('/', ltrim($path, '/'));
        $lang = 'ru';
        if ($parts && in_array($parts[0], ['kz', 'en'], true)) {
            $lang = array_shift($parts);
        }
        if ($parts && $parts[0] === 'ru') {
            $this->redirect(self::url($parts[1] ?? 'index'), 301);
        }
        $page = $parts[0] ?? 'index';
        if (count($parts) > 1 || !in_array($page, self::PAGES, true) || ($page === 'index' && $parts)) {
            http_response_code(404);
            $page = '404';
        }
        echo $this->render($page, $lang);
    }

    public function redirect(string $to, int $code = 302): never
    {
        header('Location: ' . $to, true, $code);
        exit;
    }

    public function twig(string $dir, string $lang): Environment
    {
        $twig = new Environment(new FilesystemLoader($this->root . '/templates/' . $dir), [
            'cache' => $this->storage . '/cache/twig',
            'auto_reload' => true,
            'strict_variables' => false,
        ]);
        $i18n = new I18n($this->root . '/lang', $lang, (array) $this->content->get('ui', []));
        $twig->addFunction(new TwigFunction('__', fn (string $k, array $a = []) => $i18n->t($k, ...$a), ['is_variadic' => true]));
        $twig->addFunction(new TwigFunction('url', fn (string $p = 'index', ?string $l = null) => self::url($p, $l ?? $lang)));
        $twig->addFunction(new TwigFunction('asset', function (string $p) {
            $f = $this->public . '/' . ltrim($p, '/');

            return '/' . ltrim($p, '/') . (is_file($f) ? '?v=' . filemtime($f) : '');
        }));
        $twig->addFilter(new TwigFilter('ucfirst', fn ($s) => mb_strtoupper(mb_substr((string) $s, 0, 1)) . mb_substr((string) $s, 1)));
        $wa = (string) ($this->content->get('site')['whatsapp'] ?? '');
        $twig->addFilter(new TwigFilter('wa', fn ($text) => 'https://wa.me/' . $wa . '?text=' . rawurlencode((string) $text)));

        return $twig;
    }

    public function render(string $page, string $lang): string
    {
        $c = Content::localize($this->content->all(), $lang);
        $site = $c['site'];
        $site['phone_href'] = 'tel:+' . preg_replace('~\D~', '', $site['phone']);
        $courses = array_map(fn ($x) => $x + ['url' => self::url($x['slug'], $lang)], $c['courses']);
        $twig = $this->twig('site', $lang);
        $i18n = new I18n($this->root . '/lang', $lang, (array) $this->content->get('ui', []));
        $alternates = [];
        foreach (Content::LANGS as $l) {
            $alternates[$l] = self::url($page === '404' ? 'index' : $page, $l);
        }
        $template = in_array($page, self::PAGES, true) ? $page : '404';

        $vars = [
            'page' => $page,
            'lang' => $lang,
            'html_lang' => I18n::HTML_LANG[$lang],
            'og_locale' => I18n::LOCALES[$lang],
            'alternates' => $alternates,
            'langs' => array_combine(Content::LANGS, array_map(fn ($l) => I18n::file($this->root . '/lang', $l)['lang_short'] ?? $l, Content::LANGS)),
            'title' => $i18n->t('meta.' . $page . '.title'),
            'description' => $page === '404' ? '' : $i18n->t('meta.' . $page . '.desc'),
            'canonical' => rtrim($site['base_url'], '/') . self::url($page, $lang),
            'year' => date('Y'),
            'SITE' => $site,
            'BRANCHES' => $c['branches'],
            'COURSES' => $courses,
            'COURSE' => current(array_filter($courses, fn ($x) => $x['slug'] === $page)) ?: null,
            'CONTROL' => $c['control'],
            'STATS' => $c['stats'],
            'LANGUAGES_EXTRA' => $c['languages_extra'],
            'ENGLISH' => $c['english'],
            'IELTS' => $c['ielts'],
            'MATH' => $c['math'],
            'NIS' => $c['nis'],
            'SCHOOL' => $c['school'],
            'PRODLENKA' => $c['prodlenka'],
            'TEACHERS' => $c['teachers'],
            'TEACHER_STANDARDS' => $c['teacher_standards'],
            'STEPS' => $c['steps'],
            'PHOTOS' => $c['photos'],
            'BOOKS' => $c['books'],
        ];
        // Глобальные переменные видны и в макросах
        foreach ($vars as $k => $v) {
            $twig->addGlobal($k, $v);
        }

        return $twig->render($template . '.twig');
    }

    private function lead(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        $in = fn (string $k, int $max = 120) => mb_substr(trim(strip_tags((string) ($_POST[$k] ?? ''))), 0, $max);
        // Ловушка для ботов: скрытое поле должно остаться пустым
        if ($in('website') !== '') {
            echo '{"ok":true}';

            return;
        }
        $lead = [
            'name' => $in('name', 80),
            'phone' => $in('phone', 30),
            'child' => $in('child'),
            'course' => $in('course'),
            'branch' => $in('branch'),
            'lang' => in_array($_POST['lang'] ?? '', Content::LANGS, true) ? $_POST['lang'] : 'ru',
            'page' => $in('page', 40),
            'ip' => self::ip(),
        ];
        $digits = preg_replace('~\D~', '', $lead['phone']);
        if ($lead['name'] === '' || strlen($digits) < 10 || strlen($digits) > 15) {
            http_response_code(422);
            echo '{"ok":false,"error":"invalid"}';

            return;
        }
        if ($this->leads->recentFromIp($lead['ip'], 30) >= 5) {
            http_response_code(429);
            echo '{"ok":false,"error":"rate"}';

            return;
        }
        $this->leads->add($lead);
        echo '{"ok":true}';
    }
}
