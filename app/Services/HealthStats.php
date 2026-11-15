<?php

namespace App\Services;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Данные о здоровье из метрик Samsung Health: ряды для графиков, недельная сводка
 * и простые связи («после хорошего сна шагов больше»). Только для владельца.
 */
class HealthStats
{
    public const STEPS = 'Шаги';

    public const SLEEP = 'Сон';

    public const HEART = 'Пульс, минимум за день';

    public const EXERCISE = 'Тренировки за день';

    /** @var array<string, array<string, float>> имя метрики → дата → значение */
    private array $data = [];

    public function __construct(User $user)
    {
        $metrics = $user->metrics()->whereIn('name', [self::STEPS, self::SLEEP, self::HEART, self::EXERCISE])->with('entries')->get();
        foreach ($metrics as $m) {
            $this->data[$m->name] = $m->entries->mapWithKeys(fn ($e) => [$e->date->toDateString() => (float) $e->value])->sortKeys()->all();
        }
    }

    public static function for(User $user): self
    {
        return new self($user);
    }

    public function hasData(): bool
    {
        return ! empty($this->data[self::STEPS]) || ! empty($this->data[self::SLEEP]);
    }

    public function lastDate(): ?CarbonImmutable
    {
        $all = array_merge(...array_map('array_keys', array_values($this->data) ?: [[]]));

        return $all ? CarbonImmutable::parse(max($all)) : null;
    }

    /**
     * Ряд за $days дней до последней даты: подписи, значения (null — нет данных) и среднее за 7 дней.
     * $div переводит единицы (секунды сна → часы).
     */
    public function series(string $name, int $days = 90, float $div = 1): array
    {
        $end = $this->lastDate() ?? CarbonImmutable::today();
        $values = $this->data[$name] ?? [];
        $labels = $vals = $avg = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $d = $end->subDays($i);
            $labels[] = $d->translatedFormat('j M');
            $vals[] = isset($values[$d->toDateString()]) ? round($values[$d->toDateString()] / $div, 1) : null;
            $window = array_filter(array_slice($vals, -7), fn ($v) => $v !== null);
            $avg[] = count($window) >= 3 ? round(array_sum($window) / count($window), 1) : null;
        }

        return compact('labels', 'vals', 'avg');
    }

    /** Сводка по неделям (с понедельника), последние $weeks недель с данными. */
    public function weekly(int $weeks = 8): array
    {
        $rows = [];
        $end = $this->lastDate();
        if (! $end) {
            return [];
        }
        $start = $end->startOfWeek()->subWeeks($weeks - 1);
        for ($w = 0; $w < $weeks; $w++) {
            $from = $start->addWeeks($w);
            $to = $from->addDays(6);
            $pick = fn (string $name) => collect($this->data[$name] ?? [])->filter(fn ($v, $d) => $d >= $from->toDateString() && $d <= $to->toDateString());
            $steps = $pick(self::STEPS);
            $sleep = $pick(self::SLEEP);
            $heart = $pick(self::HEART);
            $ex = $pick(self::EXERCISE);
            if ($steps->isEmpty() && $sleep->isEmpty()) {
                continue;
            }
            $rows[] = [
                'week' => $from->translatedFormat('j M').' – '.$to->translatedFormat('j M'),
                'steps' => $steps->isEmpty() ? null : (int) round($steps->avg()),
                'steps_total' => (int) $steps->sum(),
                'sleep' => $sleep->isEmpty() ? null : $sleep->avg() / 3600,
                'heart' => $heart->isEmpty() ? null : (int) round($heart->min()),
                'exercise' => (int) round($ex->sum() / 60),
            ];
        }

        return array_reverse($rows);
    }

    /**
     * Связи в ваших данных. Совет появляется, только если в каждой группе не меньше 5 дней.
     *
     * @return list<string>
     */
    public function insights(): array
    {
        $out = [];
        $steps = $this->data[self::STEPS] ?? [];
        $sleep = $this->data[self::SLEEP] ?? [];
        $ex = $this->data[self::EXERCISE] ?? [];
        $fmt = fn ($n) => number_format($n, 0, ',', ' ');

        // Сон записан на дату пробуждения — сравниваем с шагами того же дня.
        $good = $bad = [];
        foreach ($sleep as $d => $sec) {
            if (isset($steps[$d])) {
                if ($sec >= 7 * 3600) {
                    $good[] = $steps[$d];
                } else {
                    $bad[] = $steps[$d];
                }
            }
        }
        if (count($good) >= 5 && count($bad) >= 5) {
            $diff = array_sum($good) / count($good) - array_sum($bad) / count($bad);
            $out[] = $diff >= 0
                ? 'После ночей от 7 часов сна вы проходите в среднем на '.$fmt($diff).' шагов больше ('.count($good).' таких ночей против '.count($bad).').'
                : 'Интересно: после ночей от 7 часов сна шагов в среднем на '.$fmt(-$diff).' меньше ('.count($good).' ночей против '.count($bad).').';
        }

        // Тренировка днём → сон следующей ночью (запись сна — на следующую дату).
        $after = $noEx = [];
        foreach ($sleep as $d => $sec) {
            $prev = CarbonImmutable::parse($d)->subDay()->toDateString();
            if (! isset($steps[$prev])) {
                continue;
            }
            if (($ex[$prev] ?? 0) >= 20 * 60) {
                $after[] = $sec;
            } else {
                $noEx[] = $sec;
            }
        }
        if (count($after) >= 5 && count($noEx) >= 5) {
            $diff = (array_sum($after) / count($after) - array_sum($noEx) / count($noEx)) / 60;
            $out[] = 'В ночь после тренировки от 20 минут вы спите в среднем на '.$fmt(abs($diff)).' мин '.($diff >= 0 ? 'дольше' : 'меньше').' ('.count($after).' ночей).';
        }

        // Самый активный день недели.
        if (count($steps) >= 21) {
            $byDow = collect($steps)->groupBy(fn ($v, $d) => CarbonImmutable::parse($d)->dayOfWeekIso, true)->map(fn (Collection $c) => $c->avg());
            $best = $byDow->sortDesc()->keys()->first();
            $worst = $byDow->sort()->keys()->first();
            $name = fn ($i) => CarbonImmutable::parse('2026-11-16')->addDays($i - 1)->translatedFormat('l');
            $out[] = 'Больше всего вы ходите по дням «'.$name($best).'» (в среднем '.$fmt($byDow[$best]).' шагов), меньше всего — «'.$name($worst).'» ('.$fmt($byDow[$worst]).').';
        }

        return $out;
    }
}
