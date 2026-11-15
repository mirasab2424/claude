<?php

namespace App\Services;

use App\Enums\GoalStatus;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Итоги недели (пн–вс): цели, привычки, настроение, здоровье и вывод робота-тренера.
 * Только по записям за эту неделю, ничего не придумывает.
 */
class WeekReview
{
    public function __construct(private readonly User $user, private readonly CarbonImmutable $from)
    {
    }

    public static function for(User $user, CarbonImmutable $anyDay): self
    {
        return new self($user, $anyDay->startOfWeek());
    }

    public function data(): array
    {
        $from = $this->from;
        $to = $from->endOfWeek();
        $today = CarbonImmutable::today();
        $last = $to->lt($today) ? $to : $today; // для текущей недели считаем до сегодня
        $daysPassed = max(1, $from->diffInDays($last) + 1);
        $in = fn ($d) => $d && $d->gte($from) && $d->lte($to->endOfDay());

        $goals = $this->user->goals()->get();
        $done = $goals->filter(fn ($g) => $g->status === GoalStatus::Done && $in($g->completed_at))->sortBy('completed_at')->values();
        $failed = $goals->filter(fn ($g) => $g->status === GoalStatus::Failed && $in($g->completed_at))->values();
        $overdue = $goals->filter(fn ($g) => ! $g->status->isClosed() && $g->due_date && $g->due_date->lt($today))->values();
        $nextWeek = $goals->filter(fn ($g) => ! $g->status->isClosed() && $g->due_date && $g->due_date->gt($to) && $g->due_date->lte($to->addWeek()))->values();

        $habits = $this->user->habits()->active()->with('checks')->get()->map(function ($h) use ($from, $last) {
            $n = $h->checks->filter(fn ($c) => $c->date->gte($from) && $c->date->lte($last))->count();
            $plan = (int) ceil($h->days_per_week * ($from->diffInDays($last) + 1) / 7);

            return ['name' => trim(($h->icon ?? '').' '.$h->name), 'done' => $n, 'plan' => max(1, $plan)];
        });
        $habitRate = $habits->sum('plan') ? (int) round(min(1, $habits->sum('done') / $habits->sum('plan')) * 100) : null;

        $logs = $this->user->dayLogs()->whereBetween('date', [$from->toDateString(), $to->toDateString().' 23:59:59'])->get();
        $metric = fn ($name) => $this->user->metrics()->where('name', $name)->first()?->entries()
            ->whereBetween('date', [$from->toDateString(), $to->toDateString().' 23:59:59'])->get();
        $steps = $metric(HealthStats::STEPS);
        $sleep = $metric(HealthStats::SLEEP);

        $data = [
            'from' => $from, 'to' => $to, 'isCurrent' => $today->between($from, $to), 'daysPassed' => $daysPassed,
            'done' => $done, 'failed' => $failed, 'overdue' => $overdue, 'nextWeek' => $nextWeek,
            'habits' => $habits, 'habitRate' => $habitRate,
            'mood' => $logs->whereNotNull('mood')->avg('mood'), 'energy' => $logs->whereNotNull('energy')->avg('energy'),
            'notes' => $logs->filter(fn ($l) => $l->note)->sortBy('date')->values(),
            'steps' => $steps && $steps->isNotEmpty() ? (int) round($steps->avg('value')) : null,
            'sleep' => $sleep && $sleep->isNotEmpty() ? $sleep->avg('value') / 3600 : null,
        ];
        $data['verdict'] = $this->verdict($data);

        return $data;
    }

    /** Короткий вывод робота: что получилось и на чём сосредоточиться на следующей неделе. */
    private function verdict(array $d): array
    {
        $good = [];
        $focus = [];
        if ($d['done']->count()) {
            $good[] = 'выполнено целей: '.$d['done']->count();
        }
        if ($d['habitRate'] !== null) {
            $d['habitRate'] >= 80 ? $good[] = "привычки — {$d['habitRate']} % плана" : $focus[] = "привычки ({$d['habitRate']} % плана) — оставьте 1–2 самые важные";
        }
        if ($d['sleep'] !== null) {
            $d['sleep'] >= 7 ? $good[] = 'сон в норме' : $focus[] = 'сон — в среднем '.number_format($d['sleep'], 1, ',', '').' ч, нужно 7+';
        }
        if ($d['steps'] !== null) {
            $d['steps'] >= 8000 ? $good[] = 'много движения' : $focus[] = 'движение — '.number_format($d['steps'], 0, ',', ' ').' шагов в день, цель 8 000';
        }
        if ($d['overdue']->count()) {
            $focus[] = 'просроченные цели ('.$d['overdue']->count().') — перенести срок или закрыть';
        }
        if ($d['mood'] !== null && $d['mood'] < 2.5) {
            $focus[] = 'настроение — больше отдыха и приятных дел';
        }
        if (! $good && ! $focus) {
            $focus[] = 'записывайте цели, привычки и «как прошёл день» — тогда будет что подвести';
        }

        return ['good' => $good, 'focus' => array_slice($focus, 0, 3), 'mood' => ! $focus ? 'happy' : (count($focus) > count($good) ? 'worried' : 'calm')];
    }
}
