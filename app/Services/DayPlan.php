<?php

namespace App\Services;

use App\Enums\GoalStatus;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

class DayPlan
{
    public function __construct(private readonly User $user, private readonly CarbonImmutable $day) {}

    public function data(): array
    {
        $day = $this->day;
        $tasks = $this->tasks()->orderBy('due_date')->orderBy('id')->get();
        $habits = $this->habits()->with(['checks' => fn ($query) => $query->whereDate('date', $day)])->get();
        $done = $tasks->where('status', GoalStatus::Done)->count()
            + $habits->filter(fn ($habit) => $habit->checks->isNotEmpty())->count();

        return [
            'date' => $day,
            'tasks' => $tasks,
            'habits' => $habits,
            'log' => $this->user->dayLogs()->whereDate('date', $day)->first(),
            'done' => $done,
            'total' => $tasks->count() + $habits->count(),
        ];
    }

    public function completeTask(int $id, bool $done): void
    {
        $task = $this->tasks()->findOrFail($id);
        if ($task->status === GoalStatus::Done && ! $done) {
            $task->update(['status' => GoalStatus::Planned, 'progress' => 0]);
        } elseif (! $task->status->isClosed() && $done) {
            $task->update(['status' => GoalStatus::Done]);
        }
    }

    public function postponeTask(int $id): void
    {
        $task = $this->tasks()->findOrFail($id);
        if (! $task->status->isClosed()) {
            $task->update(['due_date' => $task->due_date->copy()->addDay()]);
        }
    }

    public function completeHabit(int $id, bool $done): void
    {
        abort_if($this->day->isFuture(), 422);
        $habit = $this->habits()->findOrFail($id);
        $checks = $habit->checks()->whereDate('date', $this->day);
        if ($done) {
            $checks->firstOrCreate(['date' => $this->day->startOfDay()]);
        } else {
            $checks->delete();
        }
    }

    private function tasks(): Builder
    {
        $day = $this->day;
        $cutoff = $day->min(CarbonImmutable::today());

        return $this->user->goals()->getQuery()->where(function (Builder $query) use ($day, $cutoff) {
            $query->whereDate('due_date', $day)
                ->orWhere(fn (Builder $query) => $query->whereDate('due_date', '<', $cutoff)
                    ->whereIn('status', [GoalStatus::Planned, GoalStatus::InProgress]))
                ->orWhere(fn (Builder $query) => $query->whereDate('due_date', '<', $day)
                    ->where('status', GoalStatus::Done)
                    ->whereDate('completed_at', $day));
        });
    }

    private function habits(): Builder
    {
        return $this->user->habits()->getQuery()
            ->whereDate('created_at', '<=', $this->day)
            ->where(fn (Builder $query) => $query->whereNull('archived_at')->orWhereDate('archived_at', '>', $this->day));
    }
}
