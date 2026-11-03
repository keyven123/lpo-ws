<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class Service extends Model
{
    public const ICONS = [
        'target',
        'search',
        'circle_check',
        'database',
        'megaphone',
        'laptop',
        'headphones',
        'users',
        'briefcase',
        'handshake',
        'globe',
        'chart',
        'shield',
        'sparkles',
    ];

    protected $fillable = [
        'slug',
        'name',
        'description',
        'icon',
        'is_active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('name');
    }
}
