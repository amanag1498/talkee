<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SevenUpDownRound extends Model
{
    protected $fillable = [
        'round_key',
        'status',
        'starts_at',
        'locks_at',
        'ends_at',
        'settled_at',
        'cancelled_at',
        'winning_pot',
        'winning_multiplier',
        'winning_strategy',
        'dice_one',
        'dice_two',
        'dice_total',
        'total_bet_down',
        'total_bet_seven',
        'total_bet_up',
        'total_bets_count',
        'meta',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'locks_at' => 'datetime',
        'ends_at' => 'datetime',
        'settled_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'meta' => 'array',
    ];

    public function bets(): HasMany
    {
        return $this->hasMany(SevenUpDownBet::class);
    }

    public function payouts(): HasMany
    {
        return $this->hasMany(SevenUpDownPayout::class);
    }
}
