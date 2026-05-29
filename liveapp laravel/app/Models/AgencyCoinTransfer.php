<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgencyCoinTransfer extends Model
{
    protected $fillable = [
        'agency_id',
        'agency_wallet_id',
        'agency_wallet_transaction_id',
        'user_wallet_transaction_id',
        'target_user_id',
        'admin_user_id',
        'agency_user_id',
        'direction',
        'coins',
        'note',
        'meta',
    ];

    protected $casts = [
        'coins' => 'integer',
        'meta' => 'array',
    ];

    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class);
    }

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(AgencyWallet::class, 'agency_wallet_id');
    }

    public function agencyWalletTransaction(): BelongsTo
    {
        return $this->belongsTo(AgencyWalletTransaction::class);
    }

    public function userWalletTransaction(): BelongsTo
    {
        return $this->belongsTo(WalletTransaction::class, 'user_wallet_transaction_id');
    }

    public function targetUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'target_user_id');
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_user_id');
    }

    public function agencyUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'agency_user_id');
    }
}
