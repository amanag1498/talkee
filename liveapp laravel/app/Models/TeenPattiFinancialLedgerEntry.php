<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TeenPattiFinancialLedgerEntry extends Model
{
    protected $fillable = [
        'teen_patti_financial_account_id',
        'teen_patti_round_id',
        'teen_patti_bet_id',
        'teen_patti_payout_id',
        'event_key',
        'event_type',
        'treasury_delta_coins',
        'commission_delta_coins',
        'treasury_balance_after_coins',
        'commission_balance_after_coins',
        'meta',
        'occurred_at',
    ];

    protected $casts = [
        'treasury_delta_coins' => 'integer',
        'commission_delta_coins' => 'integer',
        'treasury_balance_after_coins' => 'integer',
        'commission_balance_after_coins' => 'integer',
        'meta' => 'array',
        'occurred_at' => 'datetime',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(TeenPattiFinancialAccount::class, 'teen_patti_financial_account_id');
    }

    public function round(): BelongsTo
    {
        return $this->belongsTo(TeenPattiRound::class, 'teen_patti_round_id');
    }

    public function bet(): BelongsTo
    {
        return $this->belongsTo(TeenPattiBet::class, 'teen_patti_bet_id');
    }

    public function payout(): BelongsTo
    {
        return $this->belongsTo(TeenPattiPayout::class, 'teen_patti_payout_id');
    }
}
