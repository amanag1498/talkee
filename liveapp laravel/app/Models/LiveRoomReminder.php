<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LiveRoomReminder extends Model
{
    protected $fillable = [
        'live_room_id',
        'user_id',
        'meta',
    ];

    protected $casts = [
        'meta' => 'array',
    ];

    public function room(): BelongsTo
    {
        return $this->belongsTo(LiveRoom::class, 'live_room_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
