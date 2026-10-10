<?php

namespace App\Models;

use App\Enums\MetricDirection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Metric extends Model
{
    protected $fillable = ['user_id', 'category_id', 'name', 'unit', 'direction', 'is_duration', 'target', 'is_public'];

    protected function casts(): array
    {
        return [
            'direction' => MetricDirection::class,
            'is_duration' => 'boolean',
            'target' => 'float',
            'is_public' => 'boolean',
        ];
    }

    /** Форматирует значение: длительность в секундах показывается как м:сс (или ч:мм:сс). */
    public function format(?float $value, bool $signed = false): string
    {
        if ($value === null) {
            return '—';
        }
        $sign = $signed && $value > 0 ? '+' : ($value < 0 ? '−' : '');
        $abs = abs($value);
        if ($this->is_duration) {
            $s = (int) round($abs);
            $text = $s >= 3600
                ? sprintf('%d:%02d:%02d', intdiv($s, 3600), intdiv($s % 3600, 60), $s % 60)
                : sprintf('%d:%02d', intdiv($s, 60), $s % 60);

            return $sign.$text;
        }

        return $sign.rtrim(rtrim(number_format($abs, 2, ',', ' '), '0'), ',');
    }

    public static function parseDuration(string|int|float|null $state): ?float
    {
        if ($state === null || $state === '') {
            return null;
        }
        $state = str_replace(',', '.', trim((string) $state));
        if (! str_contains($state, ':')) {
            return (float) $state;
        }
        $parts = array_map('intval', explode(':', $state));

        return (float) array_reduce($parts, fn ($carry, $part) => $carry * 60 + $part, 0);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function entries(): HasMany
    {
        return $this->hasMany(MetricEntry::class)->orderBy('date');
    }

    public function scopePublic(Builder $query): void
    {
        $query->where('is_public', true);
    }

    /**
     * Сравнивает последнее значение с первым: положительное число — прогресс,
     * отрицательное — регресс (с учётом того, что для метрики «лучше»).
     */
    public function trend(): ?array
    {
        $entries = $this->relationLoaded('entries') ? $this->entries : $this->entries()->get();
        if ($entries->count() < 2) {
            return null;
        }

        $first = (float) $entries->first()->value;
        $last = (float) $entries->last()->value;
        $delta = $last - $first;
        $signed = $this->direction === MetricDirection::Down ? -$delta : $delta;

        return [
            'first' => $first,
            'last' => $last,
            'delta' => $delta,
            'percent' => $first != 0.0 ? round($delta / abs($first) * 100, 1) : null,
            'state' => $signed > 0 ? 'progress' : ($signed < 0 ? 'regress' : 'flat'),
        ];
    }
}
