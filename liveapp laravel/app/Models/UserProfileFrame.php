<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserProfileFrame extends Model
{
    protected $fillable = [
        'user_id',
        'profile_frame_id',
        'source',
        'granted_at',
        'expires_at',
        'is_equipped',
        'meta',
    ];

    protected $casts = [
        'granted_at' => 'datetime',
        'expires_at' => 'datetime',
        'is_equipped' => 'boolean',
        'meta' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function profileFrame(): BelongsTo
    {
        return $this->belongsTo(ProfileFrame::class);
    }
}
