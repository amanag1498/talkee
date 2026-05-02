<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserLevelHistory extends Model
{
    protected $fillable = [
        'user_id',
        'old_level_id',
        'new_level_id',
        'lifetime_spend_coins',
        'triggered_by_transaction_id',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function oldLevel(): BelongsTo
    {
        return $this->belongsTo(UserLevel::class, 'old_level_id');
    }

    public function newLevel(): BelongsTo
    {
        return $this->belongsTo(UserLevel::class, 'new_level_id');
    }

    public function walletTransaction(): BelongsTo
    {
        return $this->belongsTo(WalletTransaction::class, 'triggered_by_transaction_id');
    }
}
