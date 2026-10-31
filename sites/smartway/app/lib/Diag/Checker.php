<?php

namespace Smart\Diag;

/**
 * Проверка одного ответа по ключу из content/diagnostics.json. Возвращает долю от 0 до 1.
 * Виды ключа: choice, number, seq, pairs, expr, ineq, letters, keywords, parts.
 */
final class Checker
{
    public static function score(array $check, mixed $given): float
    {
        if ($check['type'] === 'choice') {
            return is_numeric($given) && (int) $given === (int) $check['answer'] ? 1.0 : 0.0;
        }
        $s = trim((string) $given);
        if ($s === '') {
            return 0.0;
        }

        return match ($check['type']) {
            'number' => self::number($check, $s),
            'seq' => self::seq($check, Expr::numbers($s)),
            'pairs' => self::pairs($check, Expr::numbers($s)),
            'expr' => self::expr($check, $s),
            'ineq' => self::ineq($check, $s),
            'letters' => self::letters($check, $s),
            'keywords' => self::keywords($check, $s),
            'parts' => self::parts($check, $s),
            default => 0.0,
        };
    }

    private static function eq(float $a, float $b, float $tol): bool
    {
        return abs($a - $b) <= max($tol, 1e-6 * max(1, abs($b)));
    }

    private static function number(array $c, string $s): float
    {
        $v = Expr::single($s);
        if ($v === null) {
            return 0.0;
        }
        foreach ($c['answer'] as $a) {
            if (self::eq($v, (float) $a, (float) ($c['tol'] ?? 0))) {
                return 1.0;
            }
        }

        return 0.0;
    }

    /** Список чисел: по порядку или как множество. Частичный балл — доля совпавших. */
    private static function seq(array $c, array $nums): float
    {
        $want = array_map('floatval', $c['answer']);
        $tol = (float) ($c['tol'] ?? 0);
        if (!empty($c['dedupe'])) {
            $nums = array_values(array_unique(array_map(fn ($x) => round($x, 6), $nums)));
        }
        if (!$nums) {
            return 0.0;
        }
        if ($c['ordered'] ?? true) {
            $hit = 0;
            foreach ($want as $i => $w) {
                $hit += isset($nums[$i]) && self::eq($nums[$i], $w, $tol) ? 1 : 0;
            }
        } else {
            $hit = 0;
            $left = $nums;
            foreach ($want as $w) {
                foreach ($left as $k => $n) {
                    if (self::eq($n, $w, $tol)) {
                        $hit++;
                        unset($left[$k]);
                        break;
                    }
                }
            }
            $hit -= count($left); // лишние корни снижают балл
        }
        $extra = ($c['ordered'] ?? true) ? max(0, count($nums) - count($want)) : 0;

        return max(0.0, ($hit - $extra) / count($want));
    }

    private static function pairs(array $c, array $nums): float
    {
        $got = array_chunk($nums, 2);
        $hit = 0;
        foreach ($c['answer'] as [$x, $y]) {
            foreach ($got as $k => $p) {
                if (count($p) === 2 && self::eq($p[0], $x, 0.01) && self::eq($p[1], $y, 0.01)) {
                    $hit++;
                    unset($got[$k]);
                    break;
                }
            }
        }

        return max(0.0, ($hit - count($got)) / count($c['answer']));
    }

    /** Выражение равно эталону, если совпадает в нескольких случайных точках. */
    private static function expr(array $c, string $s): float
    {
        $n = Expr::normalize($s);
        if (str_contains($n, '=')) {
            $n = substr($n, strrpos($n, '=') + 1);
        }
        if (!empty($c['product']) && !self::isProduct($n)) {
            return 0.0; // просили разложить на множители, а ответ — не произведение
        }
        $points = [[1.37, 0.61], [2.9, -1.3], [-0.73, 2.21], [3.41, 1.17], [-2.2, -0.4]];
        $ok = 0;
        $tried = 0;
        foreach ($points as [$p, $q]) {
            if (!empty($c['positive'])) {
                [$p, $q] = [abs($p), abs($q)];
            }
            $vars = [];
            foreach ($c['vars'] as $i => $v) {
                $vars[$v] = $i === 0 ? $p : $q;
            }
            $ref = Expr::evaluate($c['answer'], $vars);
            if ($ref === null) {
                continue;
            }
            $tried++;
            $got = Expr::evaluate($n, $vars);
            if ($got === null) {
                return 0.0;
            }
            $ok += self::eq($got, $ref, 1e-6 * max(1, abs($ref))) ? 1 : 0;
        }

        return $tried && $ok === $tried ? 1.0 : 0.0;
    }

    /** Нет сложения и вычитания на верхнем уровне скобок — значит, произведение. */
    private static function isProduct(string $s): bool
    {
        $s = ltrim(trim($s), '+-');
        $depth = 0;
        for ($i = 0, $n = strlen($s); $i < $n; $i++) {
            $ch = $s[$i];
            $depth += $ch === '(' ? 1 : ($ch === ')' ? -1 : 0);
            if ($depth === 0 && ($ch === '+' || $ch === '-') && $i > 0 && !in_array(trim($s[$i - 1]), ['(', '^', '*', '/'], true)) {
                return false;
            }
        }

        return str_contains($s, '(');
    }

    private static function ineq(array $c, string $s): float
    {
        $ref = Expr::solutionSet($c['answer']);
        $got = Expr::solutionSet($s);
        if (!$ref || !$got) {
            return 0.0;
        }
        for ($x = -12.0; $x <= 12.0; $x += 0.25) {
            if ($ref($x) !== $got($x)) {
                return 0.0;
            }
        }

        return 1.0;
    }

    private static function letters(array $c, string $s): float
    {
        $s = strtr(mb_strtolower($s), ['а' => 'a', 'в' => 'b', 'с' => 'c', 'б' => 'b']);
        $s = preg_replace('~\([^)]*\)~', ' ', $s);
        preg_match_all('~\b([a-z])\b~', $s, $m);
        $got = array_unique($m[1]);
        $want = $c['answer'];
        $hit = count(array_intersect($got, $want));
        $miss = count(array_diff($got, $want));

        return max(0.0, ($hit - $miss) / count($want));
    }

    private static function keywords(array $c, string $s): float
    {
        $n = Expr::normalize($s);
        if (!empty($c['not']) && preg_match('~' . $c['not'] . '~u', $n)) {
            return 0.0;
        }
        $total = 0.0;
        foreach ($c['groups'] as $g) {
            $cnt = preg_match_all('~' . $g['re'] . '~u', $n);
            $total += min(1, $cnt / ($g['min'] ?? 1));
        }

        return $total / count($c['groups']);
    }

    private static function parts(array $c, string $s): float
    {
        $nums = Expr::numbers($s);
        $sum = 0.0;
        $w = 0.0;
        foreach ($c['parts'] as $p) {
            $w += $p['w'];
            if (isset($p['has_number'])) {
                $ok = (bool) array_filter($nums, fn ($n) => self::eq($n, (float) $p['has_number'], 0.001));
            } elseif (isset($p['has_seq'])) {
                $ok = false;
                for ($i = 0; $i + count($p['has_seq']) <= count($nums); $i++) {
                    $ok = $ok || array_slice($nums, $i, count($p['has_seq'])) == array_map('floatval', $p['has_seq']);
                }
            } else {
                $ok = self::keywords(['groups' => $p['keywords']], $s) >= 1;
            }
            $sum += $ok ? $p['w'] : 0;
        }

        return $w ? $sum / $w : 0.0;
    }
}
