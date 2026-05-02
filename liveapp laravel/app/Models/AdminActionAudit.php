<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdminActionAudit extends Model
{
    protected $fillable = [
        'admin_user_id',
        'target_user_id',
        'area',
        'action',
        'entity_type',
        'entity_id',
        'reason',
        'before_state',
        'after_state',
        'meta',
    ];

    protected $casts = [
        'before_state' => 'array',
        'after_state' => 'array',
        'meta' => 'array',
    ];

    public function admin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_user_id');
    }

    public function targetUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'target_user_id');
    }
}
