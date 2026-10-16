<?php

namespace Smart;

/**
 * Контент сайта: content/default.json (из репозитория) + storage/content.json (правки из админки).
 * Деплой перезаписывает только default.json, правки админки лежат в storage и не теряются.
 */
final class Content
{
    public const LANGS = ['ru', 'kz', 'en'];

    private array $data;

    public function __construct(private string $defaultFile, private string $overrideFile, private string $backupDir)
    {
        $this->data = self::merge($this->defaults(), $this->overrides());
    }

    public function defaults(): array
    {
        return json_decode((string) file_get_contents($this->defaultFile), true, 512, JSON_THROW_ON_ERROR);
    }

    public function overrides(): array
    {
        if (!is_file($this->overrideFile)) {
            return [];
        }
        $data = json_decode((string) file_get_contents($this->overrideFile), true);

        return is_array($data) ? $data : [];
    }

    public function all(): array
    {
        return $this->data;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    /** Сохраняет раздел целиком, предыдущую версию кладёт в backups (хранятся последние 30). */
    public function save(string $key, mixed $value): void
    {
        $over = $this->overrides();
        $over[$key] = $value;
        $this->write($over);
        $this->data = self::merge($this->defaults(), $this->overrides());
    }

    /** Возвращает раздел к значению из репозитория. */
    public function reset(string $key): void
    {
        $over = $this->overrides();
        unset($over[$key]);
        $this->write($over);
        $this->data = self::merge($this->defaults(), $over);
    }

    private function write(array $over): void
    {
        if (!is_dir($this->backupDir)) {
            mkdir($this->backupDir, 0775, true);
        }
        if (is_file($this->overrideFile)) {
            copy($this->overrideFile, $this->backupDir . '/content-' . date('Ymd-His') . '-' . bin2hex(random_bytes(2)) . '.json');
            $old = glob($this->backupDir . '/content-*.json') ?: [];
            sort($old);
            foreach (array_slice($old, 0, max(0, count($old) - 30)) as $f) {
                @unlink($f);
            }
        }
        $tmp = $this->overrideFile . '.tmp';
        file_put_contents($tmp, json_encode($over, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), LOCK_EX);
        rename($tmp, $this->overrideFile);
    }

    /** Ассоциативные массивы сливаются рекурсивно, списки и значения из правок заменяют целиком. */
    public static function merge(array $base, array $over): array
    {
        foreach ($over as $k => $v) {
            if (is_array($v) && isset($base[$k]) && is_array($base[$k]) && !array_is_list($v) && !array_is_list($base[$k])) {
                $base[$k] = self::merge($base[$k], $v);
            } else {
                $base[$k] = $v;
            }
        }

        return $base;
    }

    public static function isLocalized(mixed $v): bool
    {
        return is_array($v) && $v !== [] && array_key_exists('ru', $v) && !array_diff(array_keys($v), self::LANGS)
            && !array_filter($v, 'is_array');
    }

    /** Заменяет все поля {"ru","kz","en"} строкой на нужном языке (пустой перевод — берём русский). */
    public static function localize(mixed $v, string $lang): mixed
    {
        if (self::isLocalized($v)) {
            $s = trim((string) ($v[$lang] ?? ''));

            return $s !== '' ? $s : (string) $v['ru'];
        }
        if (is_array($v)) {
            return array_map(fn ($x) => self::localize($x, $lang), $v);
        }

        return $v;
    }
}
