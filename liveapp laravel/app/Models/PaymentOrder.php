<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentOrder extends Model
{
    protected $fillable = [
        'user_id',
        'recharge_plan_id',
        'order_id',
        'amount_rupees',
        'coins',
        'bonus_coins',
        'total_coins',
        'status',
        'gateway',
        'gateway_order_id',
        'gateway_payment_id',
        'gateway_response',
        'verified_at',
    ];

    protected $casts = [
        'amount_rupees' => 'decimal:2',
        'gateway_response' => 'array',
        'verified_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function rechargePlan(): BelongsTo
    {
        return $this->belongsTo(RechargePlan::class);
    }
}
