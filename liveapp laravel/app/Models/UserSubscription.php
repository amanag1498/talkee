<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserSubscription extends Model
{
    protected $fillable = [
        'user_id','subscription_plan_id','status',
        'starts_at','ends_at','last_purchased_at','meta',
    ];

    protected $casts = [
        'starts_at'=>'datetime',
        'ends_at'=>'datetime',
        'last_purchased_at'=>'datetime',
        'meta'              => 'array', 
    ];
    protected $appends = ['is_active_now'];

  public function getIsActiveNowAttribute(): bool
    {
        $now   = now();
        $grace = 5; // seconds of tolerance

        return $this->status === 'active'
            && (!$this->starts_at || $this->starts_at->lte($now->copy()->addSeconds($grace)))
            && ($this->ends_at && $this->ends_at->gt($now->copy()->subSeconds($grace)));
    }
    public function scopeSource($q, string $source)
    {
        return $q->where('meta->source', $source);
    }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function plan(): BelongsTo { return $this->belongsTo(SubscriptionPlan::class,'subscription_plan_id'); }
}
