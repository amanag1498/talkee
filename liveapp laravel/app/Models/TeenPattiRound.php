<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TeenPattiRound extends Model
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
        'winning_strategy',
        'winning_hand',
        'losing_hand_one',
        'losing_hand_two',
        'total_bet_a',
        'total_bet_b',
        'total_bet_c',
        'total_bets_count',
        'meta',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'locks_at' => 'datetime',
        'ends_at' => 'datetime',
        'settled_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'winning_hand' => 'array',
        'losing_hand_one' => 'array',
        'losing_hand_two' => 'array',
        'meta' => 'array',
    ];

    public function bets(): HasMany
    {
        return $this->hasMany(TeenPattiBet::class);
    }

    public function payouts(): HasMany
    {
        return $this->hasMany(TeenPattiPayout::class);
    }
}
