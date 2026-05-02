<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EntryPack extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'price_coins',
        'svg_url',
        'animation_style',
        'priority',
        'duration_ms',
        'duration_days',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'price_coins' => 'integer',
        'priority' => 'integer',
        'duration_ms' => 'integer',
        'duration_days' => 'integer',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function userPacks(): HasMany
    {
        return $this->hasMany(UserEntryPack::class);
    }
}
