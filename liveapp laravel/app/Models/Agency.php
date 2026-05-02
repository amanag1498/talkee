<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Agency extends Model
{
    protected $fillable = [
        'owner_user_id',
        'name',
        'legal_name',
        'contact_email',
        'contact_phone',
        'notes',
        'is_blocked',
        'payout_percentage',
        'weekly_bonus',
    ];

    protected $casts = [
        'is_blocked'        => 'boolean',
        'payout_percentage' => 'decimal:2',
        'weekly_bonus'      => 'integer',
    ];

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function hosts(): HasMany
    {
        return $this->hasMany(Host::class); // hosts.agency_id
    }

    public function payoutReports(): HasMany
    {
        return $this->hasMany(AgencyPayoutReport::class)->latest('period_start');
    }
}
