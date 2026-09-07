<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SevenUpDownFinancialLedgerEntry extends Model
{
    protected $fillable = [
        'seven_up_down_financial_account_id',
        'seven_up_down_round_id',
        'seven_up_down_bet_id',
        'seven_up_down_payout_id',
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
        return $this->belongsTo(SevenUpDownFinancialAccount::class, 'seven_up_down_financial_account_id');
    }

    public function round(): BelongsTo
    {
        return $this->belongsTo(SevenUpDownRound::class, 'seven_up_down_round_id');
    }

    public function bet(): BelongsTo
    {
        return $this->belongsTo(SevenUpDownBet::class, 'seven_up_down_bet_id');
    }

    public function payout(): BelongsTo
    {
        return $this->belongsTo(SevenUpDownPayout::class, 'seven_up_down_payout_id');
    }
}
