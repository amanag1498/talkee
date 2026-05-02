<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LiveRoomAdminAudit extends Model
{
    protected $fillable = [
        'live_room_id',
        'admin_id',
        'target_user_id',
        'action',
        'before_status',
        'after_status',
        'reason',
        'meta',
    ];

    protected $casts = [
        'meta' => 'array',
    ];

    public function room(): BelongsTo
    {
        return $this->belongsTo(LiveRoom::class, 'live_room_id');
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_id');
    }

    public function targetUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'target_user_id');
    }
}
