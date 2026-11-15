<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Habit extends Model
{
    protected $fillable = ['user_id', 'name', 'icon', 'days_per_week', 'is_public', 'sort_order', 'archived_at'];

    protected function casts(): array
    {
        return [
            'days_per_week' => 'integer',
            'is_public' => 'boolean',
            'archived_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function checks(): HasMany
    {
        return $this->hasMany(HabitCheck::class)->orderBy('date');
    }

    public function scopeActive(Builder $query): void
    {
        $query->whereNull('archived_at');
    }

    public function scopePublic(Builder $query): void
    {
        $query->where('is_public', true);
    }

    /** Даты отметок как множество 'Y-m-d' => true. */
    public function checkedDays(): array
    {
        return $this->checks->mapWithKeys(fn (HabitCheck $c) => [$c->date->toDateString() => true])->all();
    }

    public function isDoneOn(CarbonImmutable|string $day): bool
    {
        $d = $day instanceof CarbonImmutable ? $day->toDateString() : CarbonImmutable::parse($day)->toDateString();

        return isset($this->checkedDays()[$d]);
    }

    /** Отметить или снять отметку за день. Возвращает новое состояние. */
    public function toggle(CarbonImmutable|string|null $day = null): bool
    {
        $d = ($day ? CarbonImmutable::parse($day) : CarbonImmutable::today())->toDateString();
        $existing = $this->checks()->whereDate('date', $d)->first();
        if ($existing) {
            $existing->delete();
        } else {
            $this->checks()->create(['date' => $d]);
        }
        $this->unsetRelation('checks');

        return ! $existing;
    }

    /** Серия дней подряд до сегодня (если сегодня ещё не отмечено — до вчера). */
    public function streak(?CarbonImmutable $today = null): int
    {
        $days = $this->checkedDays();
        $day = $today ?? CarbonImmutable::today();
        if (! isset($days[$day->toDateString()])) {
            $day = $day->subDay();
        }
        $n = 0;
        while (isset($days[$day->toDateString()])) {
            $n++;
            $day = $day->subDay();
        }

        return $n;
    }

    /** Лучшая серия за всё время. */
    public function bestStreak(): int
    {
        $best = $run = 0;
        $prev = null;
        foreach (array_keys($this->checkedDays()) as $d) {
            $run = $prev && CarbonImmutable::parse($prev)->addDay()->toDateString() === $d ? $run + 1 : 1;
            $best = max($best, $run);
            $prev = $d;
        }

        return $best;
    }

    /** Сколько раз отмечено на этой неделе (с понедельника) — для плана «N дней в неделю». */
    public function thisWeek(?CarbonImmutable $today = null): int
    {
        $today ??= CarbonImmutable::today();
        $from = $today->startOfWeek()->toDateString();

        return count(array_filter(array_keys($this->checkedDays()), fn ($d) => $d >= $from && $d <= $today->toDateString()));
    }

    /** Последние $days дней: [['date' => 'Y-m-d', 'done' => bool], ...] от старых к новым. */
    public function lastDays(int $days = 30, ?CarbonImmutable $today = null): array
    {
        $today ??= CarbonImmutable::today();
        $set = $this->checkedDays();
        $out = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $d = $today->subDays($i);
            $out[] = ['date' => $d->toDateString(), 'done' => isset($set[$d->toDateString()]), 'label' => $d->translatedFormat('j M, D')];
        }

        return $out;
    }
}
