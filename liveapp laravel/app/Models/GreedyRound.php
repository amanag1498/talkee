<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GreedyRound extends Model
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
        'total_bet_a',
        'total_bet_b',
        'total_bet_c',
        'total_bet_d',
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
        return $this->hasMany(GreedyBet::class);
    }

    public function payouts(): HasMany
    {
        return $this->hasMany(GreedyPayout::class);
    }
}
