<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RechargePlan extends Model
{
    protected $fillable = [
        'title',
        'amount_rupees',
        'coins',
        'bonus_coins',
        'total_coins',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'amount_rupees' => 'decimal:2',
        'is_active' => 'boolean',
    ];
}
