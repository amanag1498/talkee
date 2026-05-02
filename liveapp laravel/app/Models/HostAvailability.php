<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HostAvailability extends Model
{
    protected $fillable = [
        'user_id',
        'manual_status',
        'socket_status',
        'call_status',
        'current_call_session_id',
        'last_seen_at',
    ];

    protected $casts = [
        'last_seen_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function currentCallSession(): BelongsTo
    {
        return $this->belongsTo(CallSession::class, 'current_call_session_id');
    }
}
