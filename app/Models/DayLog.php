<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DayLog extends Model
{
    public const MOODS = [1 => '😞', 2 => '😕', 3 => '😐', 4 => '🙂', 5 => '😄'];

    public const ENERGY = [1 => 'нет сил', 2 => 'мало', 3 => 'норм', 4 => 'бодро', 5 => 'на максимум'];

    protected $fillable = ['user_id', 'date', 'mood', 'energy', 'note'];

    protected function casts(): array
    {
        return ['date' => 'date', 'mood' => 'integer', 'energy' => 'integer'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
