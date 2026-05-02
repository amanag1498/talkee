<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SubscriptionPlan extends Model
{
    protected $fillable = [
        'name',
        'price_coins',
        'duration_days',
        'perks',
        'is_active',
    ];

    protected $casts = [
        'perks'     => 'array',   // null or array
        'is_active' => 'boolean',
    ];
}
