<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HabitCheck extends Model
{
    protected $fillable = ['habit_id', 'date'];

    protected function casts(): array
    {
        return ['date' => 'date'];
    }

    public function habit(): BelongsTo
    {
        return $this->belongsTo(Habit::class);
    }
}
