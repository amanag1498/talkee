<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LiveRoomPkBattle extends Model
{
    protected $fillable = [
        'battle_id',
        'room_a_id',
        'room_b_id',
        'host_a_id',
        'host_b_id',
        'invited_by_host_id',
        'status',
        'duration_seconds',
        'score_a',
        'score_b',
        'started_at',
        'ended_at',
        'winner_room_id',
        'end_reason',
        'metadata',
    ];

    protected $casts = [
        'duration_seconds' => 'integer',
        'score_a' => 'integer',
        'score_b' => 'integer',
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function roomA(): BelongsTo
    {
        return $this->belongsTo(LiveRoom::class, 'room_a_id');
    }

    public function roomB(): BelongsTo
    {
        return $this->belongsTo(LiveRoom::class, 'room_b_id');
    }

    public function hostA(): BelongsTo
    {
        return $this->belongsTo(Host::class, 'host_a_id');
    }

    public function hostB(): BelongsTo
    {
        return $this->belongsTo(Host::class, 'host_b_id');
    }

    public function invitedByHost(): BelongsTo
    {
        return $this->belongsTo(Host::class, 'invited_by_host_id');
    }

    public function winnerRoom(): BelongsTo
    {
        return $this->belongsTo(LiveRoom::class, 'winner_room_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(LiveRoomPkEvent::class, 'pk_battle_id');
    }

    public function getEndsAtAttribute(): ?\Illuminate\Support\Carbon
    {
        if (!$this->started_at) {
            return null;
        }

        return $this->started_at->copy()->addSeconds((int) $this->duration_seconds);
    }
}
