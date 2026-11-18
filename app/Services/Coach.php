<?php

namespace App\Services;

use App\Enums\GoalStatus;
use App\Enums\MetricDirection;
use App\Models\Goal;
use App\Models\Metric;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Робот-тренер: короткие советы по данным человека (цели, метрики, Samsung Health).
 * Только правила, без ИИ и без выдумок: каждый совет опирается на конкретные записи.
 * Показывается владельцу в кабинете, поэтому видит и приватные данные.
 */
class Coach
{
    public const GOOD = 'good';

    public const WARN = 'warn';

    public const INFO = 'info';

    private Collection $metrics;

    private CarbonImmutable $today;

    private ?Collection $activeHabits = null;

    public function __construct(private readonly User $user, ?CarbonImmutable $today = null)
    {
        $this->today = $today ?? CarbonImmutable::today();
        $this->metrics = $user->metrics()->with('entries')->get()->keyBy('name');
    }

    public static function for(User $user, ?CarbonImmutable $today = null): self
    {
        return new self($user, $today);
    }

    /**
     * Советы по важности: сначала предупреждения, потом похвала, потом подсказки.
     *
     * @return list<array{tone: string, icon: string, title: string, text: string}>
     */
    public function tips(int $limit = 4): array
    {
        $tips = array_values(array_filter([
            $this->sleep(),
            $this->steps(),
            $this->overdue(),
            $this->heart(),
            $this->personalBest(),
            $this->target(),
            $this->goalStreak(),
            $this->habits(),
            $this->moodTip(),
            $this->inProgress(),
            $this->staleMetric(),
            ...$this->habitWarnings(),
            ...$this->dayLogTrends(),
            $this->missingDayLog(),
            $this->taskLoad(),
            $this->idleGoal(),
            ...$this->stepTrends(),
            $this->sleepVariability(),
        ]));
        $order = [self::WARN => 0, self::GOOD => 1, self::INFO => 2];
        usort($tips, fn ($a, $b) => $order[$a['tone']] <=> $order[$b['tone']]);

        return array_slice($tips, 0, $limit);
    }

    /** Настроение робота по советам: радуется, если предупреждений нет. */
    public function mood(array $tips): string
    {
        $warn = count(array_filter($tips, fn ($t) => $t['tone'] === self::WARN));
        $good = count(array_filter($tips, fn ($t) => $t['tone'] === self::GOOD));

        return $warn === 0 ? ($good ? 'happy' : 'calm') : ($warn > $good ? 'worried' : 'calm');
    }

    /** Дата самых свежих данных о здоровье — чтобы честно писать «по данным на …». */
    public function healthAsOf(): ?CarbonImmutable
    {
        $dates = collect(['Шаги', 'Сон', 'Пульс, минимум за день'])
            ->map(fn ($n) => $this->metrics->get($n)?->entries->max('date'))
            ->filter();

        return $dates->isEmpty() ? null : CarbonImmutable::parse($dates->max());
    }

    private function sleep(): ?array
    {
        $last = $this->recent('Сон', 7);
        if ($last->count() < 3) {
            return null;
        }
        $avg = $last->avg('value');
        $h = $this->hm($avg);
        if ($avg < 6.5 * 3600) {
            return $this->tip(self::WARN, '😴', 'Мало сна', "В среднем {$h} за последние {$last->count()} ночей. Цель — 7–8 ч: попробуйте лечь на 30 минут раньше.");
        }
        if ($avg >= 7 * 3600) {
            return $this->tip(self::GOOD, '🌙', 'Хороший сон', "В среднем {$h} за последние {$last->count()} ночей — так держать.");
        }

        return null;
    }

    private function steps(): ?array
    {
        $week = $this->recent('Шаги', 7);
        if ($week->count() < 3) {
            return null;
        }
        $avg = (int) round($week->avg('value'));
        $best = $week->sortByDesc('value')->first();
        $fmt = fn ($n) => number_format($n, 0, ',', ' ');
        if ($avg < 5000) {
            return $this->tip(self::WARN, '🚶', 'Мало движения', "В среднем {$fmt($avg)} шагов в день за неделю. Добавьте 20 минут прогулки — это ещё около 2 000 шагов.");
        }
        if ($best->value >= 10000) {
            return $this->tip(self::GOOD, '🔥', 'Отличный день', "{$best->date->translatedFormat('j F')} — {$fmt($best->value)} шагов. В среднем за неделю {$fmt($avg)}.");
        }

        return $this->tip(self::INFO, '👟', 'Шаги', "В среднем {$fmt($avg)} шагов в день. До 8 000 не хватает ".$fmt(max(0, 8000 - $avg)).'.');
    }

    private function heart(): ?array
    {
        $m = $this->metrics->get('Пульс, минимум за день');
        if (! $m || $m->entries->count() < 20) {
            return null;
        }
        $e = $m->entries->sortBy('date')->values();
        if ($e->last()->date->lt($this->today->subDays(21))) {
            return null; // данные слишком старые для совета
        }
        $recent = $e->slice(-7)->avg('value');
        $before = $e->slice(-37, 30)->avg('value');
        if (! $before) {
            return null;
        }
        $diff = round($recent - $before);
        if ($diff >= 4) {
            return $this->tip(self::WARN, '❤️', 'Пульс выше обычного', "Минимальный пульс за неделю ≈ ".round($recent)." уд/мин, на {$diff} выше месяца до этого. Бывает от недосыпа и усталости — дайте себе восстановиться.");
        }
        if ($diff <= -3) {
            return $this->tip(self::GOOD, '❤️', 'Сердце в форме', 'Минимальный пульс снизился на '.abs($diff).' уд/мин по сравнению с прошлым месяцем.');
        }

        return null;
    }

    /** Новый личный рекорд за последние 14 дней в любой метрике (кроме ежедневных данных о здоровье). */
    private function personalBest(): ?array
    {
        $daily = array_column(SamsungHealthImport::METRICS, 0);
        foreach ($this->metrics as $m) {
            if (in_array($m->name, $daily, true) || $m->entries->count() < 3) {
                continue;
            }
            $e = $m->entries->sortBy('date');
            $last = $e->last();
            if ($last->date->lt($this->today->subDays(14))) {
                continue;
            }
            $prev = $e->slice(0, -1)->pluck('value');
            $isBest = $m->direction === MetricDirection::Down ? $last->value < $prev->min() : $last->value > $prev->max();
            if ($isBest) {
                return $this->tip(self::GOOD, '🏆', 'Личный рекорд', "{$m->name}: {$m->format($last->value)}".($m->unit ? " {$m->unit}" : '').' — лучше, чем когда-либо раньше!');
            }
        }

        return null;
    }

    /** Метрика с заданной целью, до которой ближе всего (по лучшему результату). */
    private function target(): ?array
    {
        $best = null;
        foreach ($this->metrics as $m) {
            if ($m->target === null || $m->entries->isEmpty() || ! $m->target) {
                continue;
            }
            $down = $m->direction === MetricDirection::Down;
            $value = $down ? $m->entries->min('value') : $m->entries->max('value');
            $left = $down ? $value - $m->target : $m->target - $value;
            $rel = $left / abs($m->target);
            if (! $best || $rel < $best[2]) {
                $best = [$m, $left, $rel, $value];
            }
        }
        if (! $best) {
            return null;
        }
        [$m, $left, , $value] = $best;
        $unit = $m->unit && ! $m->is_duration ? ' '.$m->unit : '';
        if ($left <= 0) {
            return $this->tip(self::GOOD, '🎯', 'Цель по метрике взята', "{$m->name}: {$m->format($value)}{$unit} при цели {$m->format($m->target)}{$unit}. Поставьте новую планку!");
        }

        return $this->tip(self::INFO, '🎯', 'До цели по метрике', "{$m->name}: лучший результат {$m->format($value)}{$unit}, цель {$m->format($m->target)}{$unit} — осталось {$m->format($left)}{$unit}.");
    }

    private function overdue(): ?array
    {
        $goals = $this->user->goals()->get()->filter->isOverdue();
        if ($goals->isEmpty()) {
            return null;
        }
        $first = $goals->sortBy('due_date')->first();

        return $this->tip(self::WARN, '⏰', 'Просрочено: '.$goals->count(), "«{$first->title}» — срок был {$first->due_date->translatedFormat('j F')}. Перенесите срок или разбейте на шаги поменьше.");
    }

    private function goalStreak(): ?array
    {
        $streak = UserStats::for($this->user, publicOnly: false)->streak();

        return $streak >= 2
            ? $this->tip(self::GOOD, '⚡', "Серия {$streak} дн.", 'Каждый день что-то выполнено. Не прерывайте: одна маленькая задача сегодня — и серия растёт.')
            : null;
    }

    private function habits(): ?array
    {
        $habits = $this->activeHabits();
        if ($habits->isEmpty()) {
            return null;
        }
        $best = $habits->sortByDesc(fn ($h) => $h->streak($this->today))->first();
        if ($best->streak($this->today) >= 7) {
            return $this->tip(self::GOOD, '🔁', 'Привычка держится', "«{$best->name}» — уже {$best->streak($this->today)} дней подряд. Отлично!");
        }
        $left = $habits->reject(fn ($h) => $h->isDoneOn($this->today));
        if ($left->isNotEmpty()) {
            return $this->tip(self::INFO, '☑️', 'Привычки на сегодня', 'Ещё не отмечено: '.$left->pluck('name')->take(3)->implode(', ').'. Отметьте на инфопанели.');
        }

        return $this->tip(self::GOOD, '✅', 'Все привычки выполнены', 'Сегодня всё отмечено — так держать.');
    }

    /** Если три последние записи дневника — плохое настроение, мягко предложить отдых. */
    private function moodTip(): ?array
    {
        $last = $this->user->dayLogs()->whereNotNull('mood')->where('date', '>=', $this->today->subDays(6)->toDateString())->reorder('date', 'desc')->take(3)->get();
        if ($last->count() < 3) {
            return null;
        }
        $avg = $last->avg('mood');
        if ($avg <= 2.3) {
            return $this->tip(self::WARN, '🫶', 'Тяжёлые дни', 'Последние дни настроение на нуле. Это нормально — сделайте сегодня что-то маленькое и приятное: прогулка, звонок другу, ранний сон.');
        }
        if ($avg >= 4.3) {
            return $this->tip(self::GOOD, '😄', 'Хорошая полоса', 'Последние дни отличное настроение — самое время для сложной задачи из списка.');
        }

        return null;
    }

    private function inProgress(): ?array
    {
        /** @var Goal|null $goal */
        $goal = $this->user->goals()->where('status', GoalStatus::InProgress)->orderByRaw('due_date is null, due_date')->first();
        if (! $goal) {
            return $this->tip(self::INFO, '🎯', 'Нет активной цели', 'Поставьте одну цель на неделю — маленькую, но конкретную. Я буду напоминать.');
        }
        $text = "«{$goal->title}» — {$goal->progress}%";
        if ($goal->due_date && $goal->due_date->gte($this->today)) {
            $text .= ', осталось '.$this->today->diffInDays($goal->due_date).' дн.';
        }

        return $this->tip(self::INFO, '🎯', 'Сейчас в работе', $text.'. Какой следующий шаг можно сделать сегодня?');
    }

    /** Метрика, которую давно не замеряли (дольше 60 дней) — повод проверить форму. */
    private function staleMetric(): ?array
    {
        $daily = array_column(SamsungHealthImport::METRICS, 0);
        $stale = $this->metrics
            ->reject(fn (Metric $m) => in_array($m->name, $daily, true) || $m->entries->isEmpty())
            ->map(fn (Metric $m) => [$m, $m->entries->max('date')])
            ->filter(fn ($p) => CarbonImmutable::parse($p[1])->lt($this->today->subDays(60)))
            ->sortByDesc(fn ($p) => $p[0]->entries->count())
            ->first();
        if (! $stale) {
            return null;
        }
        [$m, $date] = $stale;

        return $this->tip(self::INFO, '📏', 'Давно не замеряли', "{$m->name} — последний замер ".CarbonImmutable::parse($date)->translatedFormat('j F Y').'. Самое время проверить форму.');
    }

    private function habitWarnings(): array
    {
        $risk = $inactive = null;
        foreach ($this->activeHabits() as $habit) {
            $days = array_filter($habit->checkedDays(), fn ($day) => $day <= $this->today->toDateString(), ARRAY_FILTER_USE_KEY);
            $last = array_key_last($days);
            if (! $last || isset($days[$this->today->toDateString()])) {
                continue;
            }
            $gap = (int) CarbonImmutable::parse($last)->diffInDays($this->today->startOfDay());
            if ($gap === 1 && ($streak = $habit->streak($this->today)) >= 3 && $streak > ($risk[1] ?? 0)) {
                $risk = [$habit->name, $streak];
            }
            if ($gap >= 7 && $gap > ($inactive[1] ?? 0)) {
                $inactive = [$habit->name, $gap];
            }
        }

        return [
            $risk ? $this->tip(self::WARN, '🔁', 'Серия привычки под угрозой', "«{$risk[0]}»: {$risk[1]} дн. подряд. Сегодня отметки ещё нет.") : null,
            $inactive ? $this->tip(self::INFO, '↩️', 'Привычка без отметок', "«{$inactive[0]}»: последняя отметка {$inactive[1]} дн. назад. Начните с одного выполнения.") : null,
        ];
    }

    private function activeHabits(): Collection
    {
        return $this->activeHabits ??= $this->user->habits()->active()
            ->whereDate('created_at', '<=', $this->today)->with('checks')->get();
    }

    private function dayLogTrends(): array
    {
        $today = $this->today->toDateString();
        $week = $this->today->subDays(6)->toDateString();
        $logs = $this->user->dayLogs()->whereDate('date', '>=', $this->today->subDays(13))
            ->whereDate('date', '<=', $today)->get();
        [$recent, $previous] = $logs->partition(fn ($log) => $log->date->toDateString() >= $week);
        $tips = [];
        foreach (['mood' => 'Настроение', 'energy' => 'Энергия'] as $key => $label) {
            $a = $recent->pluck($key)->filter(fn ($value) => $value !== null);
            $b = $previous->pluck($key)->filter(fn ($value) => $value !== null);
            if ($a->count() < 3 || $b->count() < 3) {
                continue;
            }
            $diff = $a->avg() - $b->avg();
            if (abs($diff) >= 1) {
                $delta = number_format(abs($diff), 1, ',', ' ');
                $tips[] = $diff > 0
                    ? $this->tip(self::GOOD, '☀️', $label.' выше', "За 7 дней средняя оценка выше на {$delta} из 5. Вспомните, что помогло.")
                    : $this->tip(self::WARN, '🫶', $label.' ниже', "За 7 дней средняя оценка ниже на {$delta} из 5. Оставьте время на отдых.");
            }
        }

        return $tips;
    }

    private function missingDayLog(): ?array
    {
        $last = $this->user->dayLogs()->whereDate('date', '<=', $this->today)->reorder('date', 'desc')->first();
        $gap = $last ? (int) $last->date->diffInDays($this->today->startOfDay()) : 0;

        return $gap >= 3
            ? $this->tip(self::INFO, '📝', 'Пауза в дневнике', "Последняя запись {$gap} дн. назад. Отметьте, как прошёл день.")
            : null;
    }

    private function taskLoad(): ?array
    {
        $active = $this->user->goals()->whereIn('status', [GoalStatus::Planned, GoalStatus::InProgress]);
        $count = (clone $active)->whereDate('due_date', $this->today)->count();
        if ($count > 7) {
            return $this->tip(self::WARN, '📋', 'Много задач на сегодня', "На сегодня {$count} задач. Выберите 3 главные.");
        }
        if ($count === 0 && (clone $active)->whereNull('parent_id')->exists()) {
            return $this->tip(self::INFO, '📌', 'Запланируйте шаг', 'На сегодня 0 задач. Выберите 1 шаг по активной цели.');
        }

        return null;
    }

    private function idleGoal(): ?array
    {
        $goal = $this->user->goals()->where('status', GoalStatus::InProgress)
            ->whereDate('updated_at', '<=', $this->today->subDays(14))->orderBy('updated_at')->first();
        if (! $goal) {
            return null;
        }
        $days = (int) $goal->updated_at->copy()->startOfDay()->diffInDays($this->today->startOfDay());

        return $this->tip(self::INFO, '🎯', 'Цель без движения', "«{$goal->title}» без обновлений {$days} дн. Запишите следующий шаг.");
    }

    private function stepTrends(): array
    {
        $steps = $this->dailyValues('Шаги', 14);
        $week = $this->today->subDays(6)->toDateString();
        [$recent, $previous] = $steps->partition(fn ($value, $date) => $date >= $week);
        $tips = [];
        if ($recent->count() >= 3 && $previous->count() >= 3 && $previous->avg() > 0) {
            $drop = 1 - $recent->avg() / $previous->avg();
            if ($recent->avg() <= $previous->avg() * 0.8) {
                $percent = (int) round($drop * 100);
                $tips[] = $this->tip(self::WARN, '🚶', 'Шагов стало меньше', "За неделю среднее число шагов снизилось на {$percent} %. Запланируйте прогулку.");
            }
        }
        $run = $best = 0;
        for ($offset = 6; $offset >= 0; $offset--) {
            $day = $this->today->subDays($offset)->toDateString();
            $run = $recent->get($day, 0) > 10000 ? $run + 1 : 0;
            $best = max($best, $run);
        }
        if ($best >= 3) {
            $tips[] = $this->tip(self::GOOD, '👟', 'Серия по шагам', "Больше 10 000 шагов {$best} дн. подряд. Так держать!");
        }

        return $tips;
    }

    private function sleepVariability(): ?array
    {
        if (! $this->metrics->get('Сон')?->is_duration) {
            return null;
        }
        $sleep = $this->dailyValues('Сон', 7)->filter(fn ($value) => $value > 0);
        $range = $sleep->max() - $sleep->min();
        if ($sleep->count() < 3 || $range <= 5400) {
            return null;
        }

        return $this->tip(self::INFO, '🌙', 'Неровный режим сна', 'За 7 дней разница между короткой и длинной ночью — '.$this->hm($range).'. Попробуйте ложиться в одно время.');
    }

    private function dailyValues(string $name, int $days): Collection
    {
        $start = $this->today->subDays($days - 1)->toDateString();
        $today = $this->today->toDateString();
        $entries = $this->metrics->get($name)?->entries ?? collect();

        return $entries->filter(fn ($entry) => $entry->date->toDateString() >= $start && $entry->date->toDateString() <= $today)
            ->groupBy(fn ($entry) => $entry->date->toDateString())
            ->map(fn ($day) => $day->avg('value'));
    }

    /** Последние записи метрики за $days дней до самой свежей записи. */
    private function recent(string $name, int $days): Collection
    {
        $m = $this->metrics->get($name);
        if (! $m || $m->entries->isEmpty()) {
            return collect();
        }
        $end = CarbonImmutable::parse($m->entries->max('date'));
        if ($end->lt($this->today->subDays(21))) {
            return collect(); // данные слишком старые для совета
        }

        return $m->entries->filter(fn ($e) => $e->date->gt($end->subDays($days)));
    }

    private function hm(float $seconds): string
    {
        $m = (int) round($seconds / 60);

        return intdiv($m, 60).' ч '.str_pad((string) ($m % 60), 2, '0', STR_PAD_LEFT).' мин';
    }

    private function tip(string $tone, string $icon, string $title, string $text): array
    {
        return compact('tone', 'icon', 'title', 'text');
    }
}
