<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LiveRoomSeatRequest extends Model
{
    protected $fillable = [
        'live_room_id',
        'user_id',
        'status',
        'requested_at',
        'responded_at',
        'responded_by',
        'removed_at',
        'remove_reason',
    ];

    protected $casts = [
        'requested_at' => 'datetime',
        'responded_at' => 'datetime',
        'removed_at' => 'datetime',
    ];

    public function room(): BelongsTo
    {
        return $this->belongsTo(LiveRoom::class, 'live_room_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function responder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responded_by');
    }
}
