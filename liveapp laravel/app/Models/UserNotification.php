<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserNotification extends Model
{
    // If your table name is not the plural of the class, keep this. Otherwise you can remove it.
    protected $table = 'user_notifications';

    // Fields you allow for mass assignment (used by NotifyUser::create([...]))
    protected $fillable = [
        'user_id',
        'type',
        'title',
        'body',
        'meta',
        'read_at',
    ];

    // Helpful casting (JSON + dates)
    protected $casts = [
        'meta'       => 'array',
        'read_at'    => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    // ✅ Fix: relationship for eager loading in controller -> with('user')
    public function user(): BelongsTo
    {
        // Defaults to foreign key 'user_id' and related model App\Models\User
        return $this->belongsTo(User::class, 'user_id');
    }
}
