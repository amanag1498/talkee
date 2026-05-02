<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LiveRoomPkEvent extends Model
{
    protected $fillable = [
        'pk_battle_id',
        'room_id',
        'user_id',
        'event_type',
        'coins',
        'wallet_transaction_id',
        'gift_id',
        'metadata',
    ];

    protected $casts = [
        'coins' => 'integer',
        'metadata' => 'array',
    ];

    public function battle(): BelongsTo
    {
        return $this->belongsTo(LiveRoomPkBattle::class, 'pk_battle_id');
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(LiveRoom::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function walletTransaction(): BelongsTo
    {
        return $this->belongsTo(WalletTransaction::class);
    }

    public function gift(): BelongsTo
    {
        return $this->belongsTo(Gift::class);
    }
}
