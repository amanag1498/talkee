<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RoomUserKick extends Model
{
    protected $fillable = [
        'room_id',
        'room_type',
        'host_user_id',
        'kicked_user_id',
        'reason',
        'kicked_by_user_id',
    ];

    public function hostUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'host_user_id');
    }

    public function kickedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'kicked_user_id');
    }

    public function kickedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'kicked_by_user_id');
    }
}
