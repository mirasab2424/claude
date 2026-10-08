<?php

namespace App\Models;

use App\Enums\MetricDirection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Metric extends Model
{
    protected $fillable = ['user_id', 'category_id', 'name', 'unit', 'direction', 'target', 'is_public'];

    protected function casts(): array
    {
        return [
            'direction' => MetricDirection::class,
            'target' => 'float',
            'is_public' => 'boolean',
        ];
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
