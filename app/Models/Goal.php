<?php

namespace App\Models;

use App\Enums\GoalStatus;
use App\Enums\Horizon;
use App\Enums\Importance;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Goal extends Model
{
    protected $fillable = [
        'user_id', 'category_id', 'parent_id', 'title', 'importance', 'horizon', 'status',
        'progress', 'start_date', 'due_date', 'completed_at', 'notes', 'retrospective', 'photo', 'link', 'is_public',
    ];

    protected function casts(): array
    {
        return [
            'importance' => Importance::class,
            'horizon' => Horizon::class,
            'status' => GoalStatus::class,
            'start_date' => 'date',
            'due_date' => 'date',
            'completed_at' => 'datetime',
            'is_public' => 'boolean',
            'progress' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        // Отметка о выполнении ставится и снимается автоматически вместе со статусом.
        static::saving(function (Goal $goal) {
            if ($goal->status === GoalStatus::Done) {
                $goal->progress = 100;
                $goal->completed_at ??= now();
            } elseif ($goal->status?->isClosed()) {
                $goal->completed_at ??= now();
            } else {
                $goal->completed_at = null;
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Goal::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(Goal::class, 'parent_id');
    }

    public function scopePublic(Builder $query): void
    {
        $query->where('is_public', true);
    }

    public function isOverdue(): bool
    {
        return $this->due_date !== null
            && ! $this->status->isClosed()
            && $this->due_date->isPast()
            && ! $this->due_date->isToday();
    }
}
