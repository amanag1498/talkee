<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class LiveRoomGift extends Model
{
    protected $fillable = [
        'live_room_id','gift_id','sender_user_id','quantity','coins_per_unit','total_coins','transaction_id','meta'
    ];

    protected $casts = ['meta' => 'array'];

    public function room(): BelongsTo { return $this->belongsTo(LiveRoom::class, 'live_room_id'); }
    public function gift(): BelongsTo { return $this->belongsTo(Gift::class); }
    public function sender(): BelongsTo { return $this->belongsTo(User::class, 'sender_user_id'); }
    public function earningLedger(): HasOne { return $this->hasOne(LiveRoomGiftEarningLedger::class, 'live_room_gift_id'); }
}
