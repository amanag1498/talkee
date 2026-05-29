<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AgencyWalletTransaction extends Model
{
    protected $fillable = [
        'agency_wallet_id',
        'type',
        'coins',
        'category',
        'reference',
        'reference_type',
        'reference_id',
        'description',
        'balance_before',
        'balance_after',
        'target_user_id',
        'created_by_admin_user_id',
        'created_by_agency_user_id',
        'meta',
    ];

    protected $casts = [
        'coins' => 'integer',
        'balance_before' => 'integer',
        'balance_after' => 'integer',
        'meta' => 'array',
    ];

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(AgencyWallet::class, 'agency_wallet_id');
    }

    public function targetUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'target_user_id');
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_admin_user_id');
    }

    public function agencyUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_agency_user_id');
    }

    public function transfers(): HasMany
    {
        return $this->hasMany(AgencyCoinTransfer::class)->latest('id');
    }
}
