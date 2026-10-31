<?php

namespace Smart\Diag;

/**
 * Разбор того, что ученик пишет в ответе: числа (1/4, 0,25, 5√2), выражения с переменными (108x⁸y⁷),
 * списки чисел (3;4), неравенства и промежутки (x ≥ −4, (−∞;2)∪(3;+∞)).
 */
final class Expr
{
    private const SUP = ['⁰' => '0', '¹' => '1', '²' => '2', '³' => '3', '⁴' => '4', '⁵' => '5', '⁶' => '6', '⁷' => '7', '⁸' => '8', '⁹' => '9', '⁻' => '-', 'ˣ' => 'x'];
    private const SUB = ['₀' => '0', '₁' => '1', '₂' => '2', '₃' => '3', '₄' => '4', '₅' => '5', '₆' => '6', '₇' => '7', '₈' => '8', '₉' => '9'];

    /** Единый вид: минусы, умножение, деление, степени, десятичная запятая. */
    public static function normalize(string $s): string
    {
        $s = trim(mb_strtolower($s));
        $s = strtr($s, ['−' => '-', '–' => '-', '—' => '-', '·' => '*', '×' => '*', '•' => '*', '⋅' => '*', '÷' => '/',
            '≤' => '<=', '≥' => '>=', '⩽' => '<=', '⩾' => '>=', '∞' => 'inf', 'π' => 'pi', '，' => ',', "\u{00A0}" => ' ']);
        // Надстрочные цифры → ^n
        $s = preg_replace_callback('~[⁰¹²³⁴⁵⁶⁷⁸⁹⁻ˣ]+~u', fn ($m) => '^(' . strtr($m[0], self::SUP) . ')', $s);
        $s = strtr($s, self::SUB);
        // Десятичная запятая между цифрами: 7,25 → 7.25 (а «3; 4» и «3, 4» остаются списком)
        $s = preg_replace('~(\d),(\d)~', '$1.$2', $s);
        $s = preg_replace('~\s+~u', ' ', $s);

        return trim($s);
    }

    /** Значение выражения или null. $vars — значения переменных. */
    public static function evaluate(string $s, array $vars = []): ?float
    {
        try {
            $p = new ExprParser(self::normalize($s), $vars);
            $v = $p->parse();

            return is_finite($v) ? $v : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /** Убирает подписи вроде «x =», «a10 =», «y(-2) =», «sin30° =», «x₁ =» и единицы измерения. */
    public static function stripLabels(string $s): string
    {
        $s = self::normalize($s);
        $s = preg_replace('~\b(sin|cos|tg|tan|ctg)\s*\d*\s*°?\s*=~u', ' ', $s);
        $s = preg_replace('~\b[a-zа-я]\s*\([^()=]*\)\s*=~u', ' ', $s);          // y(-2)=, c(5,2)=, d(y)=
        $s = preg_replace('~\b[a-zа-я]\s*_?\d*\s*=~u', ' ', $s);                 // x=, x1=, a10=, s5=
        $s = preg_replace('~\d+\s*!\s*=~u', ' ', $s);                            // 4! =
        $s = preg_replace('~[a-zа-яёәғқңөұүһі]{2,}(\^\([0-9-]+\))?~u', ' ', str_replace(['sqrt', 'pi', 'inf'], ['√', 'π', '∞'], $s));
        $s = str_replace(['√', 'π', '∞'], ['sqrt', 'pi', 'inf'], $s);
        $s = str_replace('°', ' ', $s);

        return trim(preg_replace('~\s+~', ' ', $s));
    }

    /** Все числа из ответа по порядку. Понимает дроби 3/4, корни 5√2, модули |−3|. */
    public static function numbers(string $s): array
    {
        $s = self::stripLabels($s);
        // ±2 → 2; -2
        $s = preg_replace('~±\s*(\d+(?:\.\d+)?)~u', '$1; -$1', $s);
        // модуль числа: |−11| → 11
        $s = preg_replace_callback('~\|\s*(-?\s*[\d.]+)\s*\|~', fn ($m) => (string) abs((float) str_replace(' ', '', $m[1])), $s);
        $re = '~(?<![\w.])(-\s*)?(\d+(?:\.\d+)?)?\s*(?:(sqrt)\s*\(?\s*(\d+(?:\.\d+)?)\s*\)?)?(?:\s*/\s*(\d+(?:\.\d+)?))?~u';
        preg_match_all($re, $s, $mm, PREG_SET_ORDER);
        $out = [];
        foreach ($mm as $m) {
            if (($m[2] ?? '') === '' && ($m[3] ?? '') === '') {
                continue;
            }
            $v = ($m[2] ?? '') !== '' ? (float) $m[2] : 1.0;
            if (($m[3] ?? '') !== '') {
                $v *= sqrt((float) $m[4]);
            }
            if (($m[5] ?? '') !== '') {
                if ((float) $m[5] == 0.0) {
                    continue;
                }
                $v /= (float) $m[5];
            }
            $out[] = trim($m[1] ?? '') === '-' ? -$v : $v;
        }

        return $out;
    }

    /** Одно число-ответ: сначала как выражение (после последнего «=»), иначе единственное число в тексте. */
    public static function single(string $s): ?float
    {
        $n = self::normalize($s);
        if (str_contains($n, '=')) {
            $n = substr($n, strrpos($n, '=') + 1);
        }
        $clean = self::stripLabels($n);
        $v = self::evaluate($clean);
        if ($v !== null) {
            return $v;
        }
        $nums = self::numbers($n);

        return count($nums) === 1 ? $nums[0] : null;
    }

    /**
     * Множество решений неравенства как функция x → bool. Понимает «x ≥ −4», «−3 ≤ x ≤ 1», «x < 2, x > 3»,
     * «x < 2 или x > 3», «(−∞; 2) ∪ (3; +∞)», «[−3; 1]».
     */
    public static function solutionSet(string $s): ?\Closure
    {
        $n = self::normalize($s);
        $n = preg_replace('~^.*?(?:x\s*∈|x\s*in\b)~u', '', $n);
        $num = '(-?\s*(?:inf|\d+(?:\.\d+)?(?:\s*/\s*\d+)?)|\+\s*inf)';
        $parts = [];
        // Промежутки
        if (preg_match_all('~([\(\[])\s*' . $num . '\s*[;,]\s*' . $num . '\s*([\)\]])~u', $n, $mm, PREG_SET_ORDER)) {
            foreach ($mm as $m) {
                $a = self::bound($m[2]);
                $b = self::bound($m[3]);
                if ($a === null || $b === null) {
                    return null;
                }
                $lo = $m[1] === '[';
                $hi = $m[4] === ']';
                $parts[] = fn (float $x) => ($lo ? $x >= $a : $x > $a) && ($hi ? $x <= $b : $x < $b);
            }

            return fn (float $x) => (bool) array_filter($parts, fn ($f) => $f($x));
        }
        $and = (bool) preg_match('~\b(и|and|және)\b|&~u', $n);
        $chunks = preg_split('~\b(?:или|or|немесе|и|and|және)\b|[;,&]|∪|\bu\b~u', $n);
        foreach ($chunks as $c) {
            $c = trim($c);
            if ($c === '') {
                continue;
            }
            // двойное: a <= x <= b
            if (preg_match('~^' . $num . '\s*(<=|<)\s*x\s*(<=|<)\s*' . $num . '$~u', $c, $m)) {
                $a = self::bound($m[1]);
                $b = self::bound($m[4]);
                [$o1, $o2] = [$m[2], $m[3]];
                $parts[] = fn (float $x) => ($o1 === '<=' ? $a <= $x : $a < $x) && ($o2 === '<=' ? $x <= $b : $x < $b);
            } elseif (preg_match('~^x\s*(<=|>=|<|>)\s*' . $num . '$~u', $c, $m)) {
                $parts[] = self::rel($m[1], self::bound($m[2]));
            } elseif (preg_match('~^' . $num . '\s*(<=|>=|<|>)\s*x$~u', $c, $m)) {
                $flip = ['<' => '>', '>' => '<', '<=' => '>=', '>=' => '<='];
                $parts[] = self::rel($flip[$m[2]], self::bound($m[1]));
            } else {
                return null;
            }
        }
        if (!$parts) {
            return null;
        }

        return $and
            ? fn (float $x) => count(array_filter($parts, fn ($f) => $f($x))) === count($parts)
            : fn (float $x) => (bool) array_filter($parts, fn ($f) => $f($x));
    }

    private static function bound(string $s): ?float
    {
        $s = str_replace(' ', '', $s);
        if (str_contains($s, 'inf')) {
            return str_starts_with($s, '-') ? -INF : INF;
        }

        return self::evaluate($s);
    }

    private static function rel(string $op, ?float $v): \Closure
    {
        return match ($op) {
            '<' => fn (float $x) => $x < $v,
            '>' => fn (float $x) => $x > $v,
            '<=' => fn (float $x) => $x <= $v,
            default => fn (float $x) => $x >= $v,
        };
    }
}

/** Рекурсивный спуск: + − · / ^, скобки, модуль, √, неявное умножение (2x, 3(x+1), 5√2). */
final class ExprParser
{
    private array $t = [];
    private int $i = 0;

    public function __construct(string $s, private array $vars)
    {
        $s = str_replace(['√', 'sqrt', ':'], [' √ ', ' √ ', '/'], $s);
        preg_match_all('~\d+(?:\.\d+)?|pi|e(?![a-z])|[a-z]|√|\*\*|[-+*/^()|]|\S~u', $s, $m);
        $this->t = array_values(array_filter($m[0], fn ($x) => trim($x) !== ''));
    }

    public function parse(): float
    {
        $v = $this->expr();
        if ($this->i < count($this->t)) {
            throw new \RuntimeException('лишнее: ' . $this->t[$this->i]);
        }

        return $v;
    }

    private function peek(): ?string
    {
        return $this->t[$this->i] ?? null;
    }

    private function eat(string $tok): void
    {
        if ($this->peek() !== $tok) {
            throw new \RuntimeException('ожидалось ' . $tok);
        }
        $this->i++;
    }

    private function expr(): float
    {
        $v = $this->term();
        while (in_array($this->peek(), ['+', '-'], true)) {
            $op = $this->t[$this->i++];
            $r = $this->term();
            $v = $op === '+' ? $v + $r : $v - $r;
        }

        return $v;
    }

    private function startsAtom(?string $t): bool
    {
        return $t !== null && ($t === '(' || $t === '√' || $t === 'pi' || preg_match('~^(\d|[a-z])~', $t));
    }

    private function term(): float
    {
        $v = $this->unary();
        while (true) {
            $p = $this->peek();
            if ($p === '*' || $p === '/') {
                $this->i++;
                $r = $this->unary();
                if ($p === '/' && $r == 0.0) {
                    throw new \RuntimeException('деление на 0');
                }
                $v = $p === '*' ? $v * $r : $v / $r;
            } elseif ($this->startsAtom($p)) {
                $v *= $this->power();
            } else {
                return $v;
            }
        }
    }

    private function unary(): float
    {
        $p = $this->peek();
        if ($p === '-' || $p === '+') {
            $this->i++;
            $v = $this->unary();

            return $p === '-' ? -$v : $v;
        }

        return $this->power();
    }

    private function power(): float
    {
        $b = $this->atom();
        if ($this->peek() === '^' || $this->peek() === '**') {
            $this->i++;
            $e = $this->unary();

            return $b ** $e;
        }

        return $b;
    }

    private function atom(): float
    {
        $t = $this->peek();
        if ($t === null) {
            throw new \RuntimeException('конец');
        }
        $this->i++;
        if ($t === '(') {
            $v = $this->expr();
            $this->eat(')');

            return $v;
        }
        if ($t === '|') {
            $v = $this->expr();
            $this->eat('|');

            return abs($v);
        }
        if ($t === '√') {
            $v = $this->power();
            if ($v < 0) {
                throw new \RuntimeException('корень из отрицательного');
            }

            return sqrt($v);
        }
        if ($t === 'pi') {
            return M_PI;
        }
        if (is_numeric($t)) {
            return (float) $t;
        }
        if (preg_match('~^[a-z]$~', $t)) {
            if (!array_key_exists($t, $this->vars)) {
                throw new \RuntimeException('неизвестная переменная ' . $t);
            }

            return (float) $this->vars[$t];
        }
        throw new \RuntimeException('символ ' . $t);
    }
}
