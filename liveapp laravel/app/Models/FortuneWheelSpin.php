<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FortuneWheelSpin extends Model
{
    public const TYPE_FREE = 'free';

    public const TYPE_PAID = 'paid';

    protected $fillable = [
        'user_id',
        'fortune_wheel_segment_id',
        'spin_type',
        'spin_cost_coins',
        'reward_type',
        'reward_value_coins',
        'entry_pack_id',
        'subscription_plan_id',
        'reward_duration_hours',
        'wallet_debit_transaction_id',
        'wallet_credit_transaction_id',
        'user_entry_pack_id',
        'user_subscription_id',
        'idempotency_key',
        'spun_for_date',
        'meta',
    ];

    protected $casts = [
        'spin_cost_coins' => 'integer',
        'reward_value_coins' => 'integer',
        'reward_duration_hours' => 'integer',
        'spun_for_date' => 'date',
        'meta' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function segment(): BelongsTo
    {
        return $this->belongsTo(FortuneWheelSegment::class, 'fortune_wheel_segment_id');
    }

    public function entryPack(): BelongsTo
    {
        return $this->belongsTo(EntryPack::class);
    }

    public function subscriptionPlan(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPlan::class);
    }

    public function walletDebitTransaction(): BelongsTo
    {
        return $this->belongsTo(WalletTransaction::class, 'wallet_debit_transaction_id');
    }

    public function walletCreditTransaction(): BelongsTo
    {
        return $this->belongsTo(WalletTransaction::class, 'wallet_credit_transaction_id');
    }

    public function userEntryPack(): BelongsTo
    {
        return $this->belongsTo(UserEntryPack::class);
    }

    public function userSubscription(): BelongsTo
    {
        return $this->belongsTo(UserSubscription::class);
    }
}
