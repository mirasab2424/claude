<?php

namespace Smart;

final class I18n
{
    public const LOCALES = ['ru' => 'ru_RU', 'kz' => 'kk_KZ', 'en' => 'en_US'];
    public const HTML_LANG = ['ru' => 'ru', 'kz' => 'kk', 'en' => 'en'];

    private array $strings = [];
    private array $fallback;

    /** @param array $overrides правки строк из админки: ['ru' => [key => text], ...] */
    public function __construct(private string $dir, public string $lang, array $overrides = [])
    {
        $this->fallback = self::file($dir, 'ru');
        $this->strings = array_merge(self::file($dir, $lang), array_filter($overrides[$lang] ?? [], fn ($s) => trim((string) $s) !== ''));
        if ($lang !== 'ru') {
            $this->fallback = array_merge($this->fallback, array_filter($overrides['ru'] ?? [], fn ($s) => trim((string) $s) !== ''));
        } else {
            $this->fallback = $this->strings;
        }
    }

    public static function file(string $dir, string $lang): array
    {
        $f = $dir . '/' . $lang . '.php';

        return is_file($f) ? require $f : [];
    }

    public function t(string $key, mixed ...$args): string
    {
        $s = $this->strings[$key] ?? $this->fallback[$key] ?? $key;

        return $args ? sprintf($s, ...$args) : $s;
    }
}
