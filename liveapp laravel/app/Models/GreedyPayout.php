<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GreedyPayout extends Model
{
    protected $fillable = [
        'greedy_round_id',
        'greedy_bet_id',
        'user_id',
        'wallet_transaction_id',
        'payout_coins',
        'status',
        'settled_at',
        'meta',
    ];

    protected $casts = [
        'settled_at' => 'datetime',
        'meta' => 'array',
    ];

    public function round(): BelongsTo
    {
        return $this->belongsTo(GreedyRound::class, 'greedy_round_id');
    }

    public function bet(): BelongsTo
    {
        return $this->belongsTo(GreedyBet::class, 'greedy_bet_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function walletTransaction(): BelongsTo
    {
        return $this->belongsTo(WalletTransaction::class);
    }
}
