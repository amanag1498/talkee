<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgencyPayoutReportItem extends Model
{
    protected $fillable = [
        'agency_payout_report_id',
        'host_id',
        'call_earnings',
        'gift_earnings',
        'live_room_earnings',
        'pk_earnings',
        'gross_earnings',
        'agency_commission',
        'host_share',
        'final_payable',
        'meta',
    ];

    protected $casts = [
        'call_earnings' => 'integer',
        'gift_earnings' => 'integer',
        'live_room_earnings' => 'integer',
        'pk_earnings' => 'integer',
        'gross_earnings' => 'integer',
        'agency_commission' => 'integer',
        'host_share' => 'integer',
        'final_payable' => 'integer',
        'meta' => 'array',
    ];

    public function report(): BelongsTo
    {
        return $this->belongsTo(AgencyPayoutReport::class, 'agency_payout_report_id');
    }

    public function host(): BelongsTo
    {
        return $this->belongsTo(Host::class);
    }

    public function getEffectiveAgencyRateAttribute(): float
    {
        $gross = max(0, (int) $this->gross_earnings);
        if ($gross === 0) {
            return 0.0;
        }

        return round(((int) $this->agency_commission / $gross) * 100, 2);
    }

    public function getEffectiveHostRateAttribute(): float
    {
        $gross = max(0, (int) $this->gross_earnings);
        if ($gross === 0) {
            return 0.0;
        }

        return round(((int) $this->host_share / $gross) * 100, 2);
    }

    public function getHostPayoutPercentageAttribute(): float
    {
        return (float) data_get($this->meta, 'host_payout_percentage', $this->effective_host_rate);
    }

    public function getAgencyPayoutPercentageAttribute(): float
    {
        return (float) data_get($this->meta, 'agency_payout_percentage', $this->effective_agency_rate);
    }

    public function getHostPayoutAttribute(): int
    {
        return (int) data_get($this->meta, 'host_payout', $this->host_share);
    }

    public function getAgencyPayoutAttribute(): int
    {
        return (int) data_get($this->meta, 'agency_payout', $this->agency_commission);
    }

    public function getTotalPayoutAttribute(): int
    {
        return (int) data_get($this->meta, 'total_payout', ((int) $this->host_share + (int) $this->agency_commission));
    }
}
