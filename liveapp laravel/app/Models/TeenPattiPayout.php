<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TeenPattiPayout extends Model
{
    protected $fillable = [
        'teen_patti_round_id',
        'teen_patti_bet_id',
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
        return $this->belongsTo(TeenPattiRound::class, 'teen_patti_round_id');
    }

    public function bet(): BelongsTo
    {
        return $this->belongsTo(TeenPattiBet::class, 'teen_patti_bet_id');
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
