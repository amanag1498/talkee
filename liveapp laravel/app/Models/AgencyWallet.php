<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AgencyWallet extends Model
{
    protected $fillable = [
        'agency_id',
        'balance',
    ];

    protected $casts = [
        'balance' => 'integer',
    ];

    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(AgencyWalletTransaction::class)->latest('id');
    }

    public function transfers(): HasMany
    {
        return $this->hasMany(AgencyCoinTransfer::class)->latest('id');
    }
}
