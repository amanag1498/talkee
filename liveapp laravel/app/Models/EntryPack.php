<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class EntryPack extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'price_coins',
        'svg_url',
        'animation_style',
        'priority',
        'duration_ms',
        'duration_days',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'price_coins' => 'integer',
        'priority' => 'integer',
        'duration_ms' => 'integer',
        'duration_days' => 'integer',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function getSvgUrlAttribute($value): ?string
    {
        if (!$value) {
            return null;
        }

        if (Str::startsWith($value, ['http://', 'https://'])) {
            return $value;
        }

        if (Str::startsWith($value, '/storage/entry-packs/')) {
            return route('media.entry-pack', ['path' => ltrim(Str::after($value, '/storage/'), '/')]);
        }

        if (Str::startsWith($value, 'entry-packs/')) {
            return route('media.entry-pack', ['path' => ltrim($value, '/')]);
        }

        if (Str::startsWith($value, '/storage/')) {
            return url($value);
        }

        return url(Storage::url($value));
    }

    public function userPacks(): HasMany
    {
        return $this->hasMany(UserEntryPack::class);
    }
}
