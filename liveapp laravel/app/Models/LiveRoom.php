<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Collection;

class LiveRoom extends Model
{
    protected $fillable = [
        'host_id','room_id','title','room_type','status','scheduled_at','started_at','ended_at','end_reason','last_activity_at','peak_viewers','max_speakers','max_participants','is_locked','topic','language','meta'
    ];

    protected $casts = [
        'scheduled_at' => 'datetime',
        'started_at'   => 'datetime',
        'ended_at'     => 'datetime',
        'last_activity_at' => 'datetime',
        'max_speakers' => 'integer',
        'max_participants' => 'integer',
        'is_locked' => 'boolean',
        'meta'         => 'array',
    ];

    public function host(): BelongsTo
    {
        return $this->belongsTo(Host::class);
    }

    public function participants()
{
    return $this->hasMany(\App\Models\LiveRoomParticipant::class);
}

public function gifts()
{
    return $this->hasMany(\App\Models\LiveRoomGift::class);
}

public function giftEarningLedgers(): HasMany
{
    return $this->hasMany(\App\Models\LiveRoomGiftEarningLedger::class);
}

public function seatRequests(): HasMany
{
    return $this->hasMany(\App\Models\LiveRoomSeatRequest::class);
}

public function adminAudits(): HasMany
{
    return $this->hasMany(\App\Models\LiveRoomAdminAudit::class);
}

public function reminders(): HasMany
{
    return $this->hasMany(\App\Models\LiveRoomReminder::class);
}

// optional helper
public function getDurationMinutesAttribute(): ?int
{
    if (!$this->started_at || !$this->ended_at) return null;
    return $this->started_at->diffInMinutes($this->ended_at);
}

public function pkBattlesAsRoomA(): HasMany
{
    return $this->hasMany(LiveRoomPkBattle::class, 'room_a_id');
}

public function pkBattlesAsRoomB(): HasMany
{
    return $this->hasMany(LiveRoomPkBattle::class, 'room_b_id');
}

public function activePkBattle(): ?LiveRoomPkBattle
{
    return LiveRoomPkBattle::query()
        ->where('status', 'active')
        ->where(function ($query) {
            $query->where('room_a_id', $this->id)->orWhere('room_b_id', $this->id);
        })
        ->latest('id')
        ->first();
}

public function allPkBattles(): Collection
{
    return LiveRoomPkBattle::query()
        ->where(function ($query) {
            $query->where('room_a_id', $this->id)->orWhere('room_b_id', $this->id);
        })
        ->latest('id')
        ->get();
}
}
