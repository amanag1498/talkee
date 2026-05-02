<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Gift extends Model
{
    public const GIFT_TYPES = ['auto', 'svg', 'gif', 'image'];
    public const ANIMATION_TIERS = ['small', 'medium', 'premium', 'legendary'];

    protected $fillable = [
        'name',
        'coins',
        'gift_url',
        'gift_type',
        'animation_tier',
        'animation_duration_ms',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'animation_duration_ms' => 'integer',
    ];

    public function roomGifts(): HasMany
    {
        return $this->hasMany(LiveRoomGift::class);
    }
}
