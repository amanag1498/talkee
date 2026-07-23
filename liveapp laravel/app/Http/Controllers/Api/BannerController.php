<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Banner;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class BannerController extends Controller
{
    public function index(Request $request)
    {
        $placement = $request->string('placement')->trim()->toString();
        $placement = $this->normalizePlacement($placement);
        $platform = $request->string('platform')->trim()->toString();
        $role = $request->string('role')->trim()->toString();

        $baseQuery = Banner::query()
            ->visible()
            ->when($platform !== '', function (Builder $q) use ($platform) {
                $q->where(function (Builder $w) use ($platform) {
                    $w->whereNull('platforms')
                        ->orWhereJsonLength('platforms', 0)
                        ->orWhereJsonContains('platforms', $platform);
                });
            })
            ->when($role !== '', function (Builder $q) use ($role) {
                $q->where(function (Builder $w) use ($role) {
                    $w->whereNull('target_roles')
                        ->orWhereJsonLength('target_roles', 0)
                        ->orWhereJsonContains('target_roles', $role);
                });
            });

        $select = [
                'id',
                'title',
                'image_url',
                'target_url',
                'placement',
                'action_type',
                'action_value',
                'button_text',
                'platforms',
                'target_roles',
                'sort_order',
                'starts_at',
                'ends_at'
            ];

        $ordered = function (Builder $q) use ($select) {
            return $q->select($select)->orderBy('sort_order')->orderByDesc('id');
        };

        $banners = $ordered((clone $baseQuery)
            ->when($placement !== '', fn (Builder $q) => $q->where('placement', $placement))
        )->get();

        // Fallback: if requested placement has no banners, return visible banners
        // for the current platform/role so index pages do not remain empty.
        if ($placement !== '' && $banners->isEmpty()) {
            $banners = $ordered((clone $baseQuery))->get();
        }

        // Final fallback: if platform/role filters are too strict, return visible banners.
        if ($banners->isEmpty()) {
            $banners = $ordered(Banner::query()->visible())->get();
        }

        // Normalize image URL for mobile clients: always return a usable absolute URL.
        return $banners->map(function ($banner) use ($request) {
            $banner->image_url = $this->normalizeImageUrl(
                (string) ($banner->image_url ?? ''),
                $request
            );
            return $banner;
        });
    }

    private function normalizeImageUrl(string $value, Request $request): string
    {
        $img = trim($value);
        if ($img === '') {
            return '';
        }

        $hostRoot = rtrim(config('app.url') ?: $request->getSchemeAndHttpHost(), '/');
        $path = parse_url($img, PHP_URL_PATH);

        // Uploaded local banners are stored under /storage/... . Rebuild them
        // against the current app host even if an older absolute host was saved.
        if (is_string($path) && Str::startsWith($path, '/storage/banners/')) {
            return $hostRoot . '/media/banner/' . ltrim(Str::after($path, '/storage/'), '/');
        }

        if (is_string($path) && Str::startsWith($path, '/storage/')) {
            return $hostRoot . $path;
        }

        if (Str::startsWith($img, ['http://', 'https://'])) {
            return $img;
        }

        return $hostRoot . '/' . ltrim($img, '/');
    }

    private function normalizePlacement(string $placement): string
    {
        $normalized = strtolower(trim($placement));

        return match ($normalized) {
            'index', 'front', 'frontpage', 'landing' => 'home',
            default => $normalized,
        };
    }
}
