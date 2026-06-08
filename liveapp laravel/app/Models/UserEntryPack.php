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
        'source',
        'charged',
        'price_coins',
        'wallet_transaction_id',
        'granted_by_admin_id',
        'purchase_reference',
        'admin_note',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'charged' => 'boolean',
        'price_coins' => 'integer',
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

    public function walletTransaction(): BelongsTo
    {
        return $this->belongsTo(WalletTransaction::class);
    }

    public function grantedByAdmin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'granted_by_admin_id');
    }

    public function scopeOrigin($query, ?string $origin)
    {
        return match ($origin) {
            'purchased' => $query->where(function ($q) {
                $q->whereIn('source', ['USER_PURCHASE', 'user_purchase'])
                    ->orWhere('charged', true)
                    ->orWhere(function ($legacy) {
                        $legacy->whereNull('source')
                            ->where(function ($inner) {
                                $inner->whereNull('purchase_key')
                                    ->orWhere('purchase_key', 'not like', 'admin-%');
                            });
                    });
            }),
            'gifted' => $query->whereIn('source', ['gift', 'promotional_gift', 'signup_gift']),
            'admin_grant' => $query->where(function ($q) {
                $q->whereIn('source', ['admin_grant', 'admin_user_360'])
                    ->orWhere('purchase_key', 'like', 'admin-user-360-%');
            })->where('charged', false),
            'admin_charged' => $query->where(function ($q) {
                $q->where('source', 'admin_charged')
                    ->orWhere(function ($inner) {
                        $inner->whereIn('source', ['admin_grant', 'admin_user_360'])
                            ->where('charged', true);
                    });
            }),
            default => $query,
        };
    }

    public function getOriginKeyAttribute(): string
    {
        $source = strtolower((string) ($this->source ?? ''));

        if ($source === 'admin_charged' || ($this->charged && str_starts_with($source, 'admin'))) {
            return 'admin_charged';
        }

        if (in_array($source, ['user_purchase', 'user purchased', 'user-purchase'], true) || $this->charged) {
            return 'purchased';
        }

        if (in_array($source, ['gift', 'promotional_gift', 'signup_gift'], true)) {
            return 'gifted';
        }

        if (in_array($source, ['admin_grant', 'admin_user_360', 'admin'], true) || str_starts_with($source, 'admin')) {
            return 'admin_grant';
        }

        if (! $this->source && str_starts_with((string) $this->purchase_key, 'admin-user-360-')) {
            return 'admin_grant';
        }

        if (! $this->source) {
            return 'purchased';
        }

        return 'other';
    }

    public function getOriginLabelAttribute(): string
    {
        return match ($this->origin_key) {
            'purchased' => 'Purchased',
            'gifted' => 'Gifted',
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
        return match ($this->origin_key) {
            'purchased' => 'Coins were charged to the user wallet.',
            'gifted' => 'Complimentary or promotional entry ownership.',
            'admin_grant' => 'Assigned manually by admin without charging coins.',
            'admin_charged' => 'Assigned/updated by admin and charged from wallet.',
            'other' => 'Custom source recorded on ownership.',
            default => 'Source not recorded.',
        };
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
