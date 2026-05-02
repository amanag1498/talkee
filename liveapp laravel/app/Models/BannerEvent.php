<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BannerEvent extends Model
{
    protected $fillable = [
        'banner_id',
        'user_id',
        'event_type',
        'placement',
        'platform',
        'role',
        'session_id',
        'context',
        'ip',
        'user_agent',
        'occurred_at',
    ];

    protected $casts = [
        'context' => 'array',
        'occurred_at' => 'datetime',
    ];

    public function banner(): BelongsTo
    {
        return $this->belongsTo(Banner::class);
    }
}

