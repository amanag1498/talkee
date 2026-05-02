<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LeaderboardDailyStat extends Model
{
    protected $fillable = [
        'subject_type',
        'subject_id',
        'stat_date',
        'gift_coins',
        'call_coins',
        'subscription_coins',
        'entry_coins',
        'total_coins',
    ];

    protected $casts = [
        'stat_date' => 'date',
        'gift_coins' => 'integer',
        'call_coins' => 'integer',
        'subscription_coins' => 'integer',
        'entry_coins' => 'integer',
        'total_coins' => 'integer',
    ];
}
