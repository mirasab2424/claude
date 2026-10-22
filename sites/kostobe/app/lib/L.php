<?php

namespace Kost;

/** Тексты на трёх языках хранятся как {"ru": …, "kz": …, "en": …}. */
final class L
{
    public const LANGS = ['ru', 'kz', 'en'];
    public const NAMES = ['ru' => 'Русский', 'kz' => 'Қазақша', 'en' => 'English'];
    public const SHORT = ['ru' => 'Рус', 'kz' => 'Қаз', 'en' => 'Eng'];
    public const HTML = ['ru' => 'ru', 'kz' => 'kk', 'en' => 'en'];

    /** Текст на нужном языке; если перевода нет — на первом заполненном. */
    public static function t(mixed $v, string $lang): string
    {
        if (!is_array($v)) {
            return (string) $v;
        }
        $s = trim((string) ($v[$lang] ?? ''));
        if ($s !== '') {
            return $s;
        }
        foreach (self::LANGS as $l) {
            if (trim((string) ($v[$l] ?? '')) !== '') {
                return trim((string) $v[$l]);
            }
        }

        return '';
    }

    public static function clean(mixed $v): array
    {
        $out = [];
        foreach (self::LANGS as $l) {
            $out[$l] = trim(str_replace("\r\n", "\n", (string) (is_array($v) ? ($v[$l] ?? '') : '')));
        }

        return $out;
    }

    public static function has(mixed $v, string $lang): bool
    {
        return is_array($v) && trim((string) ($v[$lang] ?? '')) !== '';
    }
}
