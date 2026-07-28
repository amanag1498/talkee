<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MetaAppEvent extends Model
{
    protected $fillable = [
        'user_id', 'payment_order_id', 'event_id', 'event_name', 'source',
        'platform', 'app_version', 'advertiser_tracking_enabled', 'value',
        'currency', 'properties', 'ip', 'user_agent', 'occurred_at',
    ];

    protected $casts = [
        'advertiser_tracking_enabled' => 'boolean',
        'value' => 'decimal:2',
        'properties' => 'array',
        'occurred_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function paymentOrder(): BelongsTo
    {
        return $this->belongsTo(PaymentOrder::class);
    }
}
