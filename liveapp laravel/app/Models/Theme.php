<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Theme extends Model
{
    protected $fillable = [
        'key',
        'name',
        'description',
        'unlock_type',
        'token_source',
        'is_active',
        'is_default',
        'is_limited',
        'starts_at',
        'ends_at',
        'price',
        'required_subscription_plan_id',
        'required_total_recharge',
        'required_total_gift_spend',
        'required_user_level',
        'required_host_level',
        'required_login_streak_days',
        'required_referrals',
        'required_host_followers',
        'event_key',
        'sort_order',
        'metadata',
        'remote_tokens',
        'preview_tokens',
        'min_app_version',
        'max_app_version',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_default' => 'boolean',
        'is_limited' => 'boolean',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'price' => 'decimal:2',
        'required_total_recharge' => 'decimal:2',
        'metadata' => 'array',
        'remote_tokens' => 'array',
        'preview_tokens' => 'array',
        'min_app_version' => 'integer',
        'max_app_version' => 'integer',
    ];

    public function requiredSubscriptionPlan(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPlan::class, 'required_subscription_plan_id');
    }

    public function userUnlocks(): HasMany
    {
        return $this->hasMany(UserThemeUnlock::class, 'theme_key', 'key');
    }
}
