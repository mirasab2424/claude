<?php

namespace App\Services;

use App\Enums\GoalStatus;
use App\Enums\Horizon;
use App\Models\Category;
use App\Models\Goal;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Вся аналитика по одному человеку: используется и на публичной странице,
 * и в виджетах личного кабинета. $publicOnly = true скрывает приватные записи.
 */
class UserStats
{
    /** Сколько очков опыта даёт выполненная цель в зависимости от горизонта. */
    private const HORIZON_XP = [
        'day' => 1,
        'week' => 3,
        'month' => 10,
        'year' => 40,
    ];

    private Collection $goals;

    public function __construct(
        public readonly User $user,
        public readonly bool $publicOnly = true,
    ) {
        $this->goals = $user->goals()
            ->when($publicOnly, fn ($q) => $q->public())
            ->with('category')
            ->get();
    }

    public static function for(User $user, bool $publicOnly = true): self
    {
        return new self($user, $publicOnly);
    }

    public function goals(): Collection
    {
        return $this->goals;
    }

    public function summary(): array
    {
        $byStatus = $this->goals->countBy(fn (Goal $g) => $g->status->value);
        $done = $byStatus->get(GoalStatus::Done->value, 0);
        $failed = $byStatus->get(GoalStatus::Failed->value, 0);
        $xp = $this->xp();

        return [
            'total' => $this->goals->count(),
            'done' => $done,
            'failed' => $failed,
            'dropped' => $byStatus->get(GoalStatus::Dropped->value, 0),
            'in_progress' => $byStatus->get(GoalStatus::InProgress->value, 0),
            'planned' => $byStatus->get(GoalStatus::Planned->value, 0),
            'overdue' => $this->goals->filter->isOverdue()->count(),
            // Процент успеха считаем только по закрытым целям, отменённые не учитываем.
            'success_rate' => ($done + $failed) > 0 ? (int) round($done / ($done + $failed) * 100) : null,
            'xp' => $xp,
            'level' => $this->level($xp),
            'level_progress' => $this->levelProgress($xp),
            'streak' => $this->streak(),
        ];
    }

    public function xp(): int
    {
        return (int) $this->goals
            ->where('status', GoalStatus::Done)
            ->sum(fn (Goal $g) => $g->importance->weight() * self::HORIZON_XP[$g->horizon->value]);
    }

    /** Уровень растёт квадратично: 1 → 0 XP, 2 → 25 XP, 3 → 100 XP, 4 → 225 XP… */
    public function level(int $xp): int
    {
        return (int) floor(sqrt($xp / 25)) + 1;
    }

    public function levelProgress(int $xp): int
    {
        $level = $this->level($xp);
        $from = 25 * ($level - 1) ** 2;
        $to = 25 * $level ** 2;

        return (int) round(($xp - $from) / ($to - $from) * 100);
    }

    /** Сколько дней подряд (до сегодня или вчера включительно) что-то выполнялось. */
    public function streak(): int
    {
        $days = $this->goals
            ->where('status', GoalStatus::Done)
            ->filter(fn (Goal $g) => $g->completed_at)
            ->map(fn (Goal $g) => $g->completed_at->toDateString())
            ->unique()
            ->flip();

        $day = CarbonImmutable::today();
        if (! $days->has($day->toDateString())) {
            $day = $day->subDay();
        }

        $streak = 0;
        while ($days->has($day->toDateString())) {
            $streak++;
            $day = $day->subDay();
        }

        return $streak;
    }

    /** Выполненные и проваленные цели по месяцам за последние $months месяцев. */
    public function timeline(int $months = 12): array
    {
        $start = CarbonImmutable::now()->startOfMonth()->subMonths($months - 1);
        $labels = [];
        $done = [];
        $failed = [];
        $created = [];

        for ($i = 0; $i < $months; $i++) {
            $month = $start->addMonths($i);
            $key = $month->format('Y-m');
            $labels[] = $month->translatedFormat('M y');
            $done[] = $this->goals->filter(fn (Goal $g) => $g->status === GoalStatus::Done && $g->completed_at?->format('Y-m') === $key)->count();
            $failed[] = $this->goals->filter(fn (Goal $g) => $g->status === GoalStatus::Failed && $g->completed_at?->format('Y-m') === $key)->count();
            $created[] = $this->goals->filter(fn (Goal $g) => $g->created_at?->format('Y-m') === $key)->count();
        }

        return compact('labels', 'done', 'failed', 'created');
    }

    /** Разбивка по типам задач (спорт, знание, навык…). */
    public function byCategory(): array
    {
        return $this->goals
            ->groupBy(fn (Goal $g) => $g->category_id ?? 0)
            ->map(function (Collection $goals) {
                /** @var Category|null $category */
                $category = $goals->first()->category;
                $done = $goals->where('status', GoalStatus::Done)->count();

                return [
                    'name' => $category?->label() ?? 'Без типа',
                    'color' => $category?->color ?? '#6b7280',
                    'total' => $goals->count(),
                    'done' => $done,
                    'rate' => (int) round($done / max($goals->count(), 1) * 100),
                ];
            })
            ->sortByDesc('total')
            ->values()
            ->all();
    }

    public function byHorizon(): array
    {
        return collect(Horizon::cases())
            ->map(function (Horizon $h) {
                $goals = $this->goals->where('horizon', $h);

                return [
                    'label' => $h->getLabel(),
                    'total' => $goals->count(),
                    'done' => $goals->where('status', GoalStatus::Done)->count(),
                ];
            })
            ->all();
    }

    /** Активность по дням за год — для «тепловой карты» как на GitHub. */
    public function heatmap(int $days = 365): array
    {
        $counts = $this->goals
            ->where('status', GoalStatus::Done)
            ->filter(fn (Goal $g) => $g->completed_at)
            ->countBy(fn (Goal $g) => $g->completed_at->toDateString());

        $result = [];
        $day = CarbonImmutable::today()->subDays($days - 1);
        for ($i = 0; $i < $days; $i++) {
            $date = $day->addDays($i)->toDateString();
            $result[] = ['date' => $date, 'count' => $counts->get($date, 0)];
        }

        return $result;
    }

    public function metrics(): Collection
    {
        return $this->user->metrics()
            ->when($this->publicOnly, fn ($q) => $q->public())
            ->with(['entries', 'category'])
            ->get();
    }

    public function places(): Collection
    {
        return $this->user->places()
            ->when($this->publicOnly, fn ($q) => $q->public())
            ->with('category')
            ->orderByDesc('visited_at')
            ->get();
    }
}
