<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class Gift extends Model
{
    public const GIFT_TYPES = ['auto', 'svg', 'svga', 'gif', 'image'];
    public const ANIMATION_TIERS = ['small', 'medium', 'premium', 'legendary'];

    protected $fillable = [
        'name',
        'coins',
        'gift_url',
        'gift_type',
        'animation_tier',
        'animation_duration_ms',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'animation_duration_ms' => 'integer',
    ];

    public function getGiftUrlAttribute($value): ?string
    {
        if (!$value) {
            return null;
        }

        if (Str::startsWith($value, ['http://', 'https://'])) {
            return $value;
        }

        if (Str::startsWith($value, '/storage/gifts/')) {
            return route('media.gift', ['path' => ltrim(Str::after($value, '/storage/'), '/')]);
        }

        if (Str::startsWith($value, 'gifts/')) {
            return route('media.gift', ['path' => ltrim($value, '/')]);
        }

        if (Str::startsWith($value, '/storage/')) {
            return $this->publicAssetUrl($value);
        }

        return $this->publicAssetUrl(Storage::url($value));
    }

    public function roomGifts(): HasMany
    {
        return $this->hasMany(LiveRoomGift::class);
    }

    private function publicAssetUrl(string $path): string
    {
        $normalizedPath = '/'.ltrim($path, '/');
        $request = request();

        if ($request) {
            return rtrim($request->getSchemeAndHttpHost(), '/').$normalizedPath;
        }

        return url($normalizedPath);
    }
}
