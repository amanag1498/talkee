<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class UserLevel extends Model
{
    protected $fillable = [
        'level',
        'title',
        'min_spend_coins',
        'badge_icon',
        'badge_color',
        'benefits',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'benefits' => 'array',
        'is_active' => 'boolean',
    ];

    public function users(): HasMany
    {
        return $this->hasMany(User::class, 'level_id');
    }
}
