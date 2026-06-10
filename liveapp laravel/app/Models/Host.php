<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Host extends Model
{
    protected $fillable = [
        'user_id','agency_id','stage_name','contact_phone','country','city','bio','kyc','is_blocked',
        'payout_percentage','weekly_bonus','audio_call_rate_per_minute','video_call_rate_per_minute',
        'goal_followers','goal_weekly_live_minutes','goal_weekly_gifted_coins',
        'video_rooms_enabled','audio_rooms_enabled','video_calls_enabled','audio_calls_enabled',
    ];

    protected $casts = [
        'kyc'         => 'array',
        'is_blocked'  => 'boolean',
        'video_rooms_enabled' => 'boolean',
        'audio_rooms_enabled' => 'boolean',
        'video_calls_enabled' => 'boolean',
        'audio_calls_enabled' => 'boolean',
        'payout_percentage' => 'decimal:2',
        'weekly_bonus'      => 'integer',
        'audio_call_rate_per_minute' => 'integer',
        'video_call_rate_per_minute' => 'integer',
    ];

    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function agency(): BelongsTo { return $this->belongsTo(Agency::class); }
    public function followers(): HasMany { return $this->hasMany(HostFollower::class); }

    public function userBlocks(): HasMany
    {
        return $this->hasMany(HostUserBlock::class, 'host_user_id', 'user_id')->latest('id');
    }

    public function payoutReportItems(): HasMany
    {
        return $this->hasMany(AgencyPayoutReportItem::class);
    }

    public function photos(): HasMany
    {
        return $this->hasMany(HostPhoto::class)->orderBy('sort');
    }

    /**
     * Sync up to 6 photos in fixed order (0..5). Each item may be:
     *  - string path/URL already stored (no upload)
     *  - UploadedFile instance handled outside if you prefer (store then pass path)
     */
    public function syncPhotos(array $paths): void
    {
        $paths = array_slice(array_values(array_filter($paths, fn($p) => !empty($p))), 0, 6);

        // Build desired state
        $desired = [];
        foreach ($paths as $i => $path) {
            $desired[$i] = ['path' => $path, 'sort' => $i];
        }

        // Upsert by (host_id, sort)
        foreach ($desired as $sort => $data) {
            $this->photos()->updateOrCreate(['sort' => $sort], ['path' => $data['path']]);
        }

        // Remove any extra existing photos beyond provided list
        $this->photos()->where('sort', '>=', count($desired))->delete();
    }

    public function roomTypeEnabled(string $roomType): bool
    {
        return match (strtolower($roomType)) {
            'audio' => (bool) $this->audio_rooms_enabled,
            default => (bool) $this->video_rooms_enabled,
        };
    }

    public function callTypeEnabled(string $type): bool
    {
        return match (strtolower($type)) {
            'audio' => (bool) $this->audio_calls_enabled,
            default => (bool) $this->video_calls_enabled,
        };
    }
}
