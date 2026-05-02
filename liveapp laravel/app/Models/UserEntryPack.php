<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserEntryPack extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'entry_pack_id',
        'is_active',
        'purchased_at',
        'expires_at',
        'purchase_key',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'purchased_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function entryPack(): BelongsTo
    {
        return $this->belongsTo(EntryPack::class);
    }

    public function getIsCurrentlyUsableAttribute(): bool
    {
        if (!$this->is_active) {
            return false;
        }

        if (!$this->entryPack?->is_active) {
            return false;
        }

        return !$this->expires_at || $this->expires_at->isFuture();
    }
}
