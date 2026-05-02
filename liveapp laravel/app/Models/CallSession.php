<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Builder;

class CallSession extends Model
{
    protected $fillable = [
        'caller_id',
        'receiver_id',
        'host_id',
        'agency_id',
        'type',
        'status',
        'livekit_room_name',
        'started_at',
        'accepted_at',
        'ended_at',
        'duration_seconds',
        'billable_minutes',
        'coin_rate_per_minute',
        'total_coins_charged',
        'host_earning',
        'agency_earning',
        'platform_earning',
        'end_reason',
        'billing_processed_at',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'accepted_at' => 'datetime',
        'ended_at' => 'datetime',
        'billing_processed_at' => 'datetime',
    ];

    public function caller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'caller_id');
    }

    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'receiver_id');
    }

    public function host(): BelongsTo
    {
        return $this->belongsTo(Host::class);
    }

    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class);
    }

    public function earningLedger(): HasOne
    {
        return $this->hasOne(CallEarningLedger::class);
    }

    public function scopeActiveStates(Builder $query): Builder
    {
        return $query->whereIn('status', ['requested', 'ringing', 'accepted']);
    }
}
