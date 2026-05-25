<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class GreedyBet extends Model
{
    protected $fillable = [
        'greedy_round_id',
        'user_id',
        'wallet_transaction_id',
        'pot',
        'amount',
        'multiplier',
        'payout_coins',
        'status',
        'idempotency_key',
        'placed_at',
        'settled_at',
        'refunded_at',
        'meta',
    ];

    protected $casts = [
        'placed_at' => 'datetime',
        'settled_at' => 'datetime',
        'refunded_at' => 'datetime',
        'meta' => 'array',
    ];

    public function round(): BelongsTo
    {
        return $this->belongsTo(GreedyRound::class, 'greedy_round_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function walletTransaction(): BelongsTo
    {
        return $this->belongsTo(WalletTransaction::class);
    }

    public function payout(): HasOne
    {
        return $this->hasOne(GreedyPayout::class);
    }
}
