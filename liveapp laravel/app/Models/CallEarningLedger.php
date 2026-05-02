<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CallEarningLedger extends Model
{
    protected $fillable = [
        'call_session_id',
        'caller_id',
        'host_id',
        'agency_id',
        'total_coins',
        'host_earning',
        'agency_earning',
        'platform_earning',
        'duration_seconds',
        'billable_minutes',
    ];

    protected $casts = [
        'total_coins' => 'integer',
        'host_earning' => 'integer',
        'agency_earning' => 'integer',
        'platform_earning' => 'integer',
        'duration_seconds' => 'integer',
        'billable_minutes' => 'integer',
    ];

    public function callSession(): BelongsTo
    {
        return $this->belongsTo(CallSession::class);
    }

    public function caller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'caller_id');
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
