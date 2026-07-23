<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Banner;
use App\Models\BannerEvent;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class BannerAdminController extends Controller
{
    private const PLACEMENTS = ['home', 'wallet', 'live', 'offers', 'profile'];
    private const ACTION_TYPES = ['none', 'url', 'deeplink', 'route'];
    private const PLATFORMS = ['android', 'ios', 'web'];
    private const ROLES = ['guest', 'user', 'host', 'agency', 'admin'];

    public function index(Request $request)
    {
        $fromInput = $this->normalizedFilterString((string) $request->input('from', ''));
        $toInput = $this->normalizedFilterString((string) $request->input('to', ''));

        $from = $fromInput !== ''
            ? Carbon::parse($fromInput)->startOfDay()
            : now()->subDays(30)->startOfDay();
        $to = $toInput !== ''
            ? Carbon::parse($toInput)->endOfDay()
            : now()->endOfDay();

        $baseQuery = Banner::query();

        $s = $this->normalizedFilterString($request->string('s')->trim()->toString());
        if ($s !== '') {
            $baseQuery->where('title', 'like', "%{$s}%");
        }
        $active = $this->normalizedFilterString((string) $request->input('active', ''));
        if ($active !== '') {
            $baseQuery->where('is_active', in_array($active, ['1', 'true'], true));
        }
        $placement = $this->normalizedFilterString($request->string('placement')->trim()->toString());
        if ($placement !== '') {
            $baseQuery->where('placement', $placement);
        }

        $idsQuery = (clone $baseQuery)->select('id');

        $summaryEvents = BannerEvent::query()
            ->whereIn('banner_id', $idsQuery)
            ->whereBetween('occurred_at', [$from, $to]);

        $uniqueIdentitySql = "COALESCE(CONCAT('u:', user_id), CONCAT('s:', session_id), CONCAT('ip:', ip), 'anon')";

        $listQuery = (clone $baseQuery)
            ->orderBy('sort_order')
            ->orderByDesc('id')
            ->withCount([
            'events as impressions_count' => fn ($e) => $e
                ->where('event_type', 'impression')
                ->whereBetween('occurred_at', [$from, $to]),
            'events as clicks_count' => fn ($e) => $e
                ->where('event_type', 'click')
                ->whereBetween('occurred_at', [$from, $to]),
        ]);

        $banners = $listQuery->paginate(20);
        $placements = self::PLACEMENTS;

        $pageBannerIds = $banners->getCollection()->pluck('id')->all();
        $pageMetrics = collect();

        if (!empty($pageBannerIds)) {
            $pageMetrics = BannerEvent::query()
                ->select('banner_id')
                ->selectRaw("COUNT(DISTINCT CASE WHEN event_type='impression' THEN {$uniqueIdentitySql} END) as unique_impressions_count")
                ->selectRaw("COUNT(DISTINCT CASE WHEN event_type='click' THEN {$uniqueIdentitySql} END) as unique_clicks_count")
                ->selectRaw("MAX(CASE WHEN event_type='impression' THEN occurred_at END) as last_impression_at")
                ->selectRaw("MAX(CASE WHEN event_type='click' THEN occurred_at END) as last_click_at")
                ->whereIn('banner_id', $pageBannerIds)
                ->whereBetween('occurred_at', [$from, $to])
                ->groupBy('banner_id')
                ->get()
                ->keyBy('banner_id');
        }

        $banners->setCollection(
            $banners->getCollection()->map(function ($banner) use ($pageMetrics, $request) {
                $m = $pageMetrics->get($banner->id);
                $banner->unique_impressions_count = (int) ($m->unique_impressions_count ?? 0);
                $banner->unique_clicks_count = (int) ($m->unique_clicks_count ?? 0);
                $banner->last_impression_at = $m->last_impression_at ?? null;
                $banner->last_click_at = $m->last_click_at ?? null;
                $banner->preview_url = $this->normalizePreviewUrl(
                    (string) ($banner->image_url ?? ''),
                    $request
                );
                return $banner;
            })
        );

        $performance = [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'impressions' => (int) (clone $summaryEvents)->where('event_type', 'impression')->count(),
            'clicks' => (int) (clone $summaryEvents)->where('event_type', 'click')->count(),
            'unique_impressions' => (int) (clone $summaryEvents)
                ->where('event_type', 'impression')
                ->selectRaw("COUNT(DISTINCT {$uniqueIdentitySql}) as aggregate")
                ->value('aggregate'),
            'unique_clicks' => (int) (clone $summaryEvents)
                ->where('event_type', 'click')
                ->selectRaw("COUNT(DISTINCT {$uniqueIdentitySql}) as aggregate")
                ->value('aggregate'),
        ];
        $performance['ctr'] = $performance['impressions'] > 0
            ? round(($performance['clicks'] * 100) / $performance['impressions'], 2)
            : 0.0;
        $performance['unique_ctr'] = $performance['unique_impressions'] > 0
            ? round(($performance['unique_clicks'] * 100) / $performance['unique_impressions'], 2)
            : 0.0;
        $performance['repeat_impressions'] = max(0, $performance['impressions'] - $performance['unique_impressions']);

        return view('admin.banners.index', compact('banners', 'placements', 'performance'));
    }

    public function create()
    {
        return view('admin.banners.create', [
            'placements' => self::PLACEMENTS,
            'actionTypes' => self::ACTION_TYPES,
            'platforms' => self::PLATFORMS,
            'roles' => self::ROLES,
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->validateData($request, false);
        $data['is_active'] = $request->boolean('is_active');
        $data['image_url'] = $this->resolveImageUrl($request, null, $data);

        Banner::create($data);

        return redirect()->route('admin.banners.index')->with('ok', 'Banner created.');
    }

    public function edit(Banner $banner)
    {
        $previewUrl = $this->normalizePreviewUrl(
            (string) ($banner->image_url ?? ''),
            request()
        );

        return view('admin.banners.edit', [
            'banner' => $banner,
            'previewUrl' => $previewUrl,
            'placements' => self::PLACEMENTS,
            'actionTypes' => self::ACTION_TYPES,
            'platforms' => self::PLATFORMS,
            'roles' => self::ROLES,
        ]);
    }

    public function update(Request $request, Banner $banner)
    {
        $data = $this->validateData($request, true);
        $data['is_active'] = $request->boolean('is_active');
        $data['image_url'] = $this->resolveImageUrl($request, $banner, $data);

        $banner->update($data);

        return redirect()->route('admin.banners.index')->with('ok', 'Banner updated.');
    }

    public function destroy(Banner $banner)
    {
        $this->deleteLocalBannerImage((string) $banner->getRawOriginal('image_url'));
        $banner->delete();

        return back()->with('ok', 'Banner deleted.');
    }

    private function validateData(Request $request, bool $isUpdate): array
    {
        $data = $request->validate([
            'title' => 'required|string|max:120',
            'image_file' => [$isUpdate ? 'nullable' : 'required_without:image_url', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
            'image_url' => [$isUpdate ? 'nullable' : 'required_without:image_file', 'nullable', 'string', 'max:2048'],
            'target_url' => 'nullable|string|max:2048',
            'placement' => 'required|in:' . implode(',', self::PLACEMENTS),
            'action_type' => 'required|in:' . implode(',', self::ACTION_TYPES),
            'action_value' => 'nullable|string|max:2048|required_unless:action_type,none',
            'button_text' => 'nullable|string|max:60',
            'platforms' => 'nullable|array',
            'platforms.*' => 'in:' . implode(',', self::PLATFORMS),
            'target_roles' => 'nullable|array',
            'target_roles.*' => 'in:' . implode(',', self::ROLES),
            'is_active' => 'nullable|boolean',
            'sort_order' => 'nullable|integer|min:0|max:100000',
            'starts_at' => 'nullable|date',
            'ends_at' => 'nullable|date|after_or_equal:starts_at',
        ]);

        $data['platforms'] = array_values(array_unique($data['platforms'] ?? []));
        $data['target_roles'] = array_values(array_unique($data['target_roles'] ?? []));
        if ($data['action_type'] === 'none') {
            $data['action_value'] = null;
        }
        unset($data['image_file']);

        return $data;
    }

    private function resolveImageUrl(Request $request, ?Banner $banner, array $data): string
    {
        if ($request->hasFile('image_file')) {
            if ($banner) {
                $this->deleteLocalBannerImage((string) $banner->getRawOriginal('image_url'));
            }
            $stored = $request->file('image_file')->store('banners', 'public');
            return Storage::url($stored);
        }

        if (!empty($data['image_url'])) {
            return (string) $data['image_url'];
        }

        return (string) ($banner?->getRawOriginal('image_url') ?? '');
    }

    private function normalizePreviewUrl(string $value, Request $request): string
    {
        $img = trim($value);
        if ($img === '') {
            return '';
        }

        $hostRoot = rtrim(config('app.url') ?: $request->getSchemeAndHttpHost(), '/');
        $path = parse_url($img, PHP_URL_PATH);

        if (is_string($path) && Str::startsWith($path, '/storage/')) {
            return $hostRoot . $path;
        }

        if (Str::startsWith($img, ['http://', 'https://'])) {
            return $img;
        }

        if (Str::startsWith($img, '/')) {
            return $hostRoot . $img;
        }

        return $hostRoot . '/' . ltrim($img, '/');
    }

    private function deleteLocalBannerImage(?string $url): void
    {
        if (!$url) {
            return;
        }

        $path = parse_url($url, PHP_URL_PATH) ?: $url;
        if (!Str::startsWith($path, '/storage/')) {
            return;
        }

        $relative = ltrim(Str::replaceFirst('/storage/', '', $path), '/');
        if ($relative !== '' && Storage::disk('public')->exists($relative)) {
            Storage::disk('public')->delete($relative);
        }
    }

    private function normalizedFilterString(string $value): string
    {
        $normalized = trim($value);
        if (in_array(strtolower($normalized), ['', 'null', 'undefined'], true)) {
            return '';
        }

        return $normalized;
    }
}
