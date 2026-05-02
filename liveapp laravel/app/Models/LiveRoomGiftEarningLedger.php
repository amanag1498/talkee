<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LiveRoomGiftEarningLedger extends Model
{
    protected $fillable = [
        'live_room_gift_id',
        'live_room_id',
        'sender_user_id',
        'host_id',
        'agency_id',
        'total_coins',
        'host_payout_percentage',
        'agency_payout_percentage',
        'host_payout_coins',
        'agency_payout_coins',
        'platform_revenue_coins',
    ];

    protected $casts = [
        'total_coins' => 'integer',
        'host_payout_percentage' => 'decimal:2',
        'agency_payout_percentage' => 'decimal:2',
        'host_payout_coins' => 'integer',
        'agency_payout_coins' => 'integer',
        'platform_revenue_coins' => 'integer',
    ];

    public function roomGift(): BelongsTo
    {
        return $this->belongsTo(LiveRoomGift::class, 'live_room_gift_id');
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(LiveRoom::class, 'live_room_id');
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_user_id');
    }

    public function host(): BelongsTo
    {
        return $this->belongsTo(Host::class);
    }

    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class);
    }
}
