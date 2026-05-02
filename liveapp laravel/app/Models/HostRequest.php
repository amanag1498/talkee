<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HostRequest extends Model
{
    protected $fillable = [
        'user_id',
        'stage_name',
        'contact_phone',
        'country',
        'city',
        'about',
        'status',
        'reviewed_by',
        'reviewed_at',
        'review_notes',
    ];

    protected $casts = [
        'reviewed_at' => 'datetime',
        'created_at'  => 'datetime',
        'updated_at'  => 'datetime',
    ];

    // ✅ applicant
    public function user(): BelongsTo
    {
        // change 'user_id' here only if your column is named differently
        return $this->belongsTo(User::class, 'user_id');
    }

    // (optional) admin who reviewed
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
