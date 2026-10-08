<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MetricEntry extends Model
{
    protected $fillable = ['metric_id', 'date', 'value', 'note'];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'value' => 'float',
        ];
    }

    public function metric(): BelongsTo
    {
        return $this->belongsTo(Metric::class);
    }
}
