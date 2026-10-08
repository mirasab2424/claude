<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Category extends Model
{
    protected $fillable = ['name', 'slug', 'icon', 'color', 'sort_order'];

    protected static function booted(): void
    {
        static::saving(function (Category $category) {
            if (blank($category->slug)) {
                $category->slug = Str::slug($category->name) ?: Str::random(8);
            }
        });
    }

    public function goals(): HasMany
    {
        return $this->hasMany(Goal::class);
    }

    public function label(): string
    {
        return trim(($this->icon ? $this->icon.' ' : '').$this->name);
    }
}
