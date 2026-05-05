<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class ProfileFrame extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'asset_url',
        'thumbnail_url',
        'rarity',
        'category',
        'unlock_type',
        'valid_days',
        'sort_order',
        'is_active',
        'meta',
    ];

    protected $casts = [
        'valid_days' => 'integer',
        'sort_order' => 'integer',
        'is_active' => 'boolean',
        'meta' => 'array',
    ];

    public function getAssetUrlAttribute($value): ?string
    {
        return $this->resolveFrameUrl($value);
    }

    public function getThumbnailUrlAttribute($value): ?string
    {
        return $this->resolveFrameUrl($value);
    }

    public function userOwnerships(): HasMany
    {
        return $this->hasMany(UserProfileFrame::class);
    }

    private function resolveFrameUrl($value): ?string
    {
        if (!$value) {
            return null;
        }

        $raw = trim((string) $value);
        if ($raw === '') {
            return null;
        }

        if (Str::startsWith($raw, ['http://', 'https://'])) {
            return $this->normalizeAbsoluteUrl($raw);
        }

        if (Str::startsWith($raw, '/media/profile-frame/')) {
            return $this->publicMediaUrl($raw);
        }

        if (Str::startsWith($raw, 'media/profile-frame/')) {
            return $this->publicMediaUrl('/'.$raw);
        }

        if (Str::startsWith($raw, '/profile-frames/')) {
            return $this->publicMediaUrl('/media/profile-frame/'.ltrim($raw, '/'));
        }

        if (Str::startsWith($raw, 'profile-frames/')) {
            return $this->publicMediaUrl('/media/profile-frame/'.$raw);
        }

        return $raw;
    }

    private function publicMediaUrl(string $path): string
    {
        $normalizedPath = '/'.ltrim($path, '/');
        $request = request();

        if ($request) {
            $publicOrigin = trim((string) $request->header('X-Public-Origin', ''));
            if ($publicOrigin !== '') {
                return rtrim($publicOrigin, '/').$normalizedPath;
            }

            return rtrim($request->getSchemeAndHttpHost(), '/').$normalizedPath;
        }

        return url($normalizedPath);
    }

    private function normalizeAbsoluteUrl(string $url): string
    {
        $request = request();
        if (!$request) {
            return $url;
        }

        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if (!in_array($host, ['127.0.0.1', 'localhost'], true)) {
            return $url;
        }

        $path = (string) (parse_url($url, PHP_URL_PATH) ?: '');
        if ($path === '') {
            return $url;
        }

        return $this->publicMediaUrl($path);
    }
}
