<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TeenPattiFinancialAccount extends Model
{
    protected $fillable = [
        'game_key',
        'treasury_balance_coins',
        'company_commission_balance_coins',
    ];

    protected $casts = [
        'treasury_balance_coins' => 'integer',
        'company_commission_balance_coins' => 'integer',
    ];

    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(TeenPattiFinancialLedgerEntry::class);
    }
}
