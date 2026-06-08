<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserSubscription extends Model
{
    protected $fillable = [
        'user_id',
        'subscription_plan_id',
        'status',
        'starts_at',
        'ends_at',
        'last_purchased_at',
        'meta',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'last_purchased_at' => 'datetime',
        'meta' => 'array',
    ];

    protected $appends = ['is_active_now'];

    public function getIsActiveNowAttribute(): bool
    {
        $now = now();
        $grace = 5; // seconds of tolerance

        return $this->status === 'active'
            && (! $this->starts_at || $this->starts_at->lte($now->copy()->addSeconds($grace)))
            && ($this->ends_at && $this->ends_at->gt($now->copy()->subSeconds($grace)));
    }

    public function scopeSource($query, string $source)
    {
        return $query->where('meta->source', $source);
    }

    public function scopeOrigin($query, ?string $origin)
    {
        return match ($origin) {
            'purchased' => $query->where(function ($q) {
                $q->where('meta->source', 'USER_PURCHASE')
                    ->orWhere('meta->source', 'user_purchase')
                    ->orWhere(function ($charged) {
                        $charged->where('meta->charged', true)
                            ->whereNotIn('meta->source', ['admin', 'admin_user_360', 'admin_grant', 'admin_charged']);
                    })
                    ->orWhere('meta->event', 'like', '%PURCHASE%');
            }),
            'gifted' => $query->where(function ($q) {
                $q->where('meta->source', 'signup_gift')
                    ->orWhere('meta->source', 'gift')
                    ->orWhere('meta->source', 'free_gift');
            }),
            'admin_grant' => $query->where(function ($q) {
                $q->whereIn('meta->source', ['admin', 'admin_user_360', 'admin_grant'])
                    ->where(function ($inner) {
                        $inner->whereNull('meta->charged')
                            ->orWhere('meta->charged', false);
                    });
            }),
            'admin_charged' => $query->where(function ($q) {
                $q->where('meta->source', 'admin_charged')
                    ->orWhere(function ($innerQuery) {
                        $innerQuery->whereIn('meta->source', ['admin', 'admin_user_360', 'admin_grant'])
                            ->where(function ($inner) {
                                $inner->where('meta->charged', true)
                                    ->orWhere('meta->event', 'like', '%CHARGED%')
                                    ->orWhere('meta->last_action', 'like', '%CHARGED%');
                            });
                    });
            }),
            default => $query,
        };
    }

    public function getOriginKeyAttribute(): string
    {
        $meta = is_array($this->meta) ? $this->meta : [];
        $source = strtolower((string) ($meta['source'] ?? ''));
        $event = strtoupper((string) ($meta['event'] ?? $meta['last_action'] ?? ''));
        $charged = filter_var($meta['charged'] ?? false, FILTER_VALIDATE_BOOL);

        if ($source === 'admin_charged' || ($charged && str_starts_with($source, 'admin'))) {
            return 'admin_charged';
        }

        if (in_array($source, ['user_purchase', 'user purchased', 'user-purchase'], true) || str_contains($event, 'PURCHASE')) {
            return 'purchased';
        }

        if ($charged) {
            return 'purchased';
        }

        if (in_array($source, ['signup_gift', 'gift', 'free_gift'], true)) {
            return 'gifted';
        }

        if (in_array($source, ['admin', 'admin_user_360', 'admin_grant'], true) || str_starts_with($source, 'admin')) {
            return 'admin_grant';
        }

        return $source !== '' ? 'other' : 'unknown';
    }

    public function getOriginLabelAttribute(): string
    {
        return match ($this->origin_key) {
            'purchased' => 'Purchased',
            'gifted' => 'Signup Gift',
            'admin_grant' => 'Admin Grant',
            'admin_charged' => 'Admin Charged',
            'other' => 'Other',
            default => 'Unknown',
        };
    }

    public function getOriginBadgeClassAttribute(): string
    {
        return match ($this->origin_key) {
            'purchased' => 'bg-success',
            'gifted' => 'bg-info text-dark',
            'admin_grant' => 'bg-primary',
            'admin_charged' => 'bg-warning text-dark',
            'other' => 'bg-secondary',
            default => 'bg-light text-dark border',
        };
    }

    public function getOriginDescriptionAttribute(): string
    {
        $meta = is_array($this->meta) ? $this->meta : [];
        $source = (string) ($meta['source'] ?? '-');
        $event = (string) ($meta['event'] ?? $meta['last_action'] ?? '');
        $charged = filter_var($meta['charged'] ?? false, FILTER_VALIDATE_BOOL);

        return match ($this->origin_key) {
            'purchased' => 'Coins were charged to the user wallet.',
            'gifted' => 'Complimentary signup or promotional subscription.',
            'admin_grant' => 'Granted manually by admin without charging coins.',
            'admin_charged' => 'Created/updated by admin and charged from wallet.',
            'other' => trim("Source: {$source}" . ($event !== '' ? " - {$event}" : '')),
            default => $charged ? 'Charged subscription.' : 'Source not recorded.',
        };
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPlan::class, 'subscription_plan_id');
    }
}
