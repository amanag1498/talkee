<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FortuneWheelSegment extends Model
{
    public const REWARD_COINS = 'coins';

    public const REWARD_ENTRY_PACK = 'entry_pack';

    public const REWARD_SUBSCRIPTION = 'subscription';

    public const REWARD_TYPES = [
        self::REWARD_COINS,
        self::REWARD_ENTRY_PACK,
        self::REWARD_SUBSCRIPTION,
    ];

    protected $fillable = [
        'label',
        'reward_type',
        'reward_value_coins',
        'entry_pack_id',
        'subscription_plan_id',
        'reward_duration_hours',
        'weight',
        'color',
        'icon_url',
        'is_active',
        'sort_order',
        'meta',
    ];

    protected $casts = [
        'reward_value_coins' => 'integer',
        'reward_duration_hours' => 'integer',
        'weight' => 'integer',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
        'meta' => 'array',
    ];

    public function entryPack(): BelongsTo
    {
        return $this->belongsTo(EntryPack::class);
    }

    public function subscriptionPlan(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPlan::class);
    }

    public function spins(): HasMany
    {
        return $this->hasMany(FortuneWheelSpin::class);
    }
}
