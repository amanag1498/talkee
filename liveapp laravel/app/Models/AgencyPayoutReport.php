<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AgencyPayoutReport extends Model
{
    protected $fillable = [
        'agency_id',
        'period_start',
        'period_end',
        'gross_earnings',
        'platform_commission',
        'agency_commission',
        'host_share',
        'deductions',
        'final_payable',
        'status',
        'generated_at',
        'approved_at',
        'published_at',
        'published_by_admin_user_id',
        'paid_at',
        'admin_remarks',
        'meta',
    ];

    protected $casts = [
        'period_start' => 'datetime',
        'period_end' => 'datetime',
        'gross_earnings' => 'integer',
        'platform_commission' => 'integer',
        'agency_commission' => 'integer',
        'host_share' => 'integer',
        'deductions' => 'integer',
        'final_payable' => 'integer',
        'generated_at' => 'datetime',
        'approved_at' => 'datetime',
        'published_at' => 'datetime',
        'paid_at' => 'datetime',
        'meta' => 'array',
    ];

    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(AgencyPayoutReportItem::class)->orderBy('host_id');
    }

    public function publishedByAdmin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by_admin_user_id');
    }

    public function getTotalHostsAttribute(): int
    {
        return (int) $this->items->count();
    }

    public function getActiveHostsCountAttribute(): int
    {
        return (int) $this->items->filter(function (AgencyPayoutReportItem $item) {
            return $item->gross_earnings > 0
                || $item->gift_earnings > 0
                || $item->live_room_earnings > 0
                || $item->pk_earnings > 0;
        })->count();
    }

    public function getTotalCallEarningsAttribute(): int
    {
        return (int) $this->items->sum('call_earnings');
    }

    public function getTotalGiftEarningsAttribute(): int
    {
        return (int) $this->items->sum('gift_earnings');
    }

    public function getTotalLiveRoomEarningsAttribute(): int
    {
        return (int) $this->items->sum('live_room_earnings');
    }

    public function getTotalPkEarningsAttribute(): int
    {
        return (int) $this->items->sum('pk_earnings');
    }

    public function getTotalCallCountAttribute(): int
    {
        return (int) data_get($this->meta, 'totals.call_count', 0);
    }

    public function getTotalBillableMinutesAttribute(): int
    {
        return (int) data_get($this->meta, 'totals.billable_minutes', 0);
    }

    public function getTotalGiftEventsAttribute(): int
    {
        return (int) data_get($this->meta, 'totals.gift_events', 0);
    }

    public function getTotalGiftQuantityAttribute(): int
    {
        return (int) data_get($this->meta, 'totals.gift_quantity', 0);
    }

    public function getTotalLiveRoomCountAttribute(): int
    {
        return (int) data_get($this->meta, 'totals.live_room_count', 0);
    }

    public function getTotalAudioRoomCountAttribute(): int
    {
        return (int) data_get($this->meta, 'totals.audio_room_count', 0);
    }

    public function getTotalVideoRoomCountAttribute(): int
    {
        return (int) data_get($this->meta, 'totals.video_room_count', 0);
    }

    public function getTotalPkEventCountAttribute(): int
    {
        return (int) data_get($this->meta, 'totals.pk_event_count', 0);
    }

    public function getTotalVideoCallMinutesAttribute(): int
    {
        return (int) data_get($this->meta, 'totals.video_call_minutes', 0);
    }

    public function getTotalVideoCallGrossAttribute(): int
    {
        return (int) data_get($this->meta, 'totals.video_call_gross', 0);
    }

    public function getTotalAudioCallMinutesAttribute(): int
    {
        return (int) data_get($this->meta, 'totals.audio_call_minutes', 0);
    }

    public function getTotalAudioCallGrossAttribute(): int
    {
        return (int) data_get($this->meta, 'totals.audio_call_gross', 0);
    }

    public function getTotalVideoRoomMinutesAttribute(): int
    {
        return (int) data_get($this->meta, 'totals.video_room_minutes', 0);
    }

    public function getTotalAudioRoomMinutesAttribute(): int
    {
        return (int) data_get($this->meta, 'totals.audio_room_minutes', 0);
    }

    public function getTotalVideoGiftGrossAttribute(): int
    {
        return (int) data_get($this->meta, 'totals.video_gift_gross', 0);
    }

    public function getTotalAudioGiftGrossAttribute(): int
    {
        return (int) data_get($this->meta, 'totals.audio_gift_gross', 0);
    }

    public function getTotalPayoutAttribute(): int
    {
        return (int) data_get($this->meta, 'totals.total_payout', ((int) $this->agency_commission + (int) $this->host_share));
    }

    public function getTotalCoinsAttribute(): int
    {
        return (int) data_get($this->meta, 'totals.total_coins', $this->gross_earnings);
    }

    public function getTotalAgencyCommissionCoinsAttribute(): int
    {
        return (int) data_get($this->meta, 'totals.agency_commission_coins', $this->agency_commission);
    }

    public function getTotalCoinsToBePaidAttribute(): int
    {
        return (int) data_get($this->meta, 'totals.total_coins_to_be_paid', $this->final_payable);
    }

    public function getTotalBonusCoinsAttribute(): int
    {
        return (int) data_get($this->meta, 'totals.bonus_coins', 0);
    }

    public function getTotalInrAttribute(): float
    {
        return (float) data_get($this->meta, 'totals.total_inr', 0);
    }

    public function getTotalVideoGiftCoinsAttribute(): int
    {
        return (int) data_get($this->meta, 'totals.video_gift_coins', $this->total_video_gift_gross);
    }

    public function getTotalAudioGiftCoinsAttribute(): int
    {
        return (int) data_get($this->meta, 'totals.audio_gift_coins', $this->total_audio_gift_gross);
    }

    public function getTotalPkGiftCoinsAttribute(): int
    {
        return (int) data_get($this->meta, 'totals.pk_gift_coins', $this->total_pk_earnings);
    }

    public function getTotalVideoCallCoinsAttribute(): int
    {
        return (int) data_get($this->meta, 'totals.video_call_coins', $this->total_video_call_gross);
    }

    public function getTotalAudioCallCoinsAttribute(): int
    {
        return (int) data_get($this->meta, 'totals.audio_call_coins', $this->total_audio_call_gross);
    }
}
