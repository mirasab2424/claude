<?php

namespace App\Services;

use App\Enums\GoalStatus;
use App\Enums\Horizon;
use App\Models\Goal;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class MonthReview
{
    public function __construct(private readonly User $user, private readonly CarbonImmutable $month) {}

    public function data(): array
    {
        $from = $this->month->startOfMonth();
        $current = $this->summarize($from);
        $previous = $this->summarize($from->subMonthNoOverflow());

        return ['reviewMonth' => $from, 'current' => $current, 'previous' => $previous['totals']];
    }

    private function summarize(CarbonImmutable $from): array
    {
        $today = CarbonImmutable::today();
        $end = $from->endOfMonth();
        $last = $end->min($today->endOfDay());
        $startKey = $from->toDateString();
        $lastKey = $last->toDateString();

        $goals = $this->user->goals()->where('status', GoalStatus::Done)
            ->whereBetween('completed_at', [$from, $last])->get(['horizon', 'parent_id', 'completed_at']);
        $tasks = $goals->filter(fn (Goal $goal) => $goal->horizon === Horizon::Day || $goal->parent_id !== null);
        $habits = $this->user->habits()->whereDate('created_at', '<=', $lastKey)
            ->where(fn ($query) => $query->whereNull('archived_at')->orWhereDate('archived_at', '>', $startKey))
            ->with(['checks' => fn ($query) => $query->whereDate('date', '>=', $startKey)->whereDate('date', '<=', $lastKey)])->get();
        $logs = $this->user->dayLogs()->whereDate('date', '>=', $startKey)->whereDate('date', '<=', $lastKey)->get();
        $metrics = $this->user->metrics()->with(['entries' => fn ($query) => $query->whereDate('date', '>=', $startKey)->whereDate('date', '<=', $lastKey)])->get();
        $entries = $metrics->flatMap(fn ($metric) => $metric->entries);
        $checks = $habits->flatMap(fn ($habit) => $habit->checks);

        $planned = $habits->sum(function ($habit) use ($from, $last) {
            $firstDay = $from->max($habit->created_at->toImmutable()->startOfDay());
            $lastDay = $habit->archived_at ? $last->min($habit->archived_at->toImmutable()->subDay()) : $last;
            $days = $firstDay->diffInDays($lastDay->startOfDay()) + 1;

            return (int) ceil(max(0, $days) * $habit->days_per_week / 7);
        });
        $streak = null;
        foreach ($habits as $habit) {
            $run = 0;
            $previous = null;
            foreach ($habit->checks as $check) {
                $run = $previous && $previous->addDay()->isSameDay($check->date) ? $run + 1 : 1;
                if ($run > ($streak['days'] ?? 0)) {
                    $streak = ['name' => $habit->name, 'days' => $run];
                }
                $previous = $check->date->toImmutable();
            }
        }

        $activity = collect()
            ->concat($goals->map(fn ($goal) => $goal->completed_at->toDateString()))
            ->concat($checks->map(fn ($check) => $check->date->toDateString()))
            ->concat($logs->map(fn ($log) => $log->date->toDateString()))
            ->concat($entries->map(fn ($entry) => $entry->date->toDateString()))
            ->countBy()->sortKeys();
        $days = [];
        for ($date = $from; $date->lte($last); $date = $date->addDay()) {
            $days[] = ['date' => $date, 'count' => $activity->get($date->toDateString(), 0)];
        }

        return [
            'totals' => [
                'tasks' => $tasks->count(),
                'goals' => $goals->count() - $tasks->count(),
                'checks' => $checks->count(),
                'habitRate' => $planned ? (int) round(100 * min(1, $checks->count() / $planned)) : null,
                'steps' => $this->dailyAverage($metrics->where('name', HealthStats::STEPS)->flatMap(fn ($metric) => $metric->entries)),
                'sleep' => $this->dailyAverage($metrics->where('name', HealthStats::SLEEP)->where('is_duration', true)->flatMap(fn ($metric) => $metric->entries)),
                'mood' => $logs->avg('mood'),
                'energy' => $logs->avg('energy'),
                'entries' => $entries->count(),
            ],
            'days' => $days,
            'bestDay' => $activity->isEmpty() ? null : CarbonImmutable::parse($activity->sortDesc()->keys()->first()),
            'streak' => $streak,
        ];
    }

    private function dailyAverage(Collection $entries): ?float
    {
        return $entries->groupBy(fn ($entry) => $entry->date->toDateString())
            ->map(fn ($day) => $day->avg('value'))->avg();
    }
}
