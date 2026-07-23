<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class Banner extends Model
{
    protected $fillable = [
        'title',
        'image_url',
        'target_url',
        'placement',
        'action_type',
        'action_value',
        'button_text',
        'platforms',
        'target_roles',
        'is_active',
        'sort_order',
        'starts_at',
        'ends_at',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'platforms' => 'array',
        'target_roles' => 'array',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
    ];

    public function getImageUrlAttribute($value): ?string
    {
        if (!$value) {
            return null;
        }

        $path = parse_url($value, PHP_URL_PATH);

        if (is_string($path) && Str::startsWith($path, '/storage/banners/')) {
            return route('media.banner', ['path' => ltrim(Str::after($path, '/storage/'), '/')]);
        }

        if (Str::startsWith($value, 'banners/')) {
            return route('media.banner', ['path' => ltrim($value, '/')]);
        }

        if (Str::startsWith($value, ['http://', 'https://'])) {
            return $value;
        }

        return url(Str::startsWith($value, '/storage/') ? $value : Storage::url($value));
    }

    public function scopeVisible(Builder $query): Builder
    {
        $now = now();

        return $query
            ->where('is_active', true)
            ->where(function (Builder $q) use ($now) {
                $q->whereNull('starts_at')->orWhere('starts_at', '<=', $now);
            })
            ->where(function (Builder $q) use ($now) {
                $q->whereNull('ends_at')->orWhere('ends_at', '>=', $now);
            });
    }

    public function events(): HasMany
    {
        return $this->hasMany(BannerEvent::class);
    }
}
