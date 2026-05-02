<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LiveRoomParticipant extends Model
{
    protected $fillable = [
        'live_room_id','user_id','session_id','role','joined_at','left_at',
        'last_seen_at','duration_seconds','muted_by_host','removed_by_host','device','country','ip_address','user_agent','meta'
    ];

    protected $casts = [
        'joined_at' => 'datetime',
        'left_at'   => 'datetime',
        'last_seen_at' => 'datetime',
        'muted_by_host' => 'boolean',
        'removed_by_host' => 'boolean',
        'meta'      => 'array',
    ];

    public function room(): BelongsTo { return $this->belongsTo(LiveRoom::class, 'live_room_id'); }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
}
