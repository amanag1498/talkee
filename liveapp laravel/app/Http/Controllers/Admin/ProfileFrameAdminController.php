<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ProfileFrame;
use App\Services\ProfileFrameAwardService;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ProfileFrameAdminController extends Controller
{
    public function index(Request $request)
    {
        $query = ProfileFrame::query()->orderBy('sort_order')->orderBy('id');

        if ($search = trim((string) $request->query('s', ''))) {
            $query->where(function ($inner) use ($search) {
                $inner->where('name', 'like', "%{$search}%")
                    ->orWhere('slug', 'like', "%{$search}%");
            });
        }

        if ($request->filled('active')) {
            $query->where('is_active', $request->query('active') === '1');
        }

        $frames = $query->paginate(24);

        return view('admin.profile-frames.index', compact('frames'));
    }

    public function create()
    {
        return view('admin.profile-frames.create', [
            'frame' => new ProfileFrame([
                'rarity' => 'rare',
                'category' => 'general',
                'unlock_type' => 'free_catalog',
                'is_active' => true,
                'sort_order' => 0,
            ]),
        ]);
    }

    public function runAwards(ProfileFrameAwardService $awards)
    {
        $report = $awards->runSevenDayAwards();

        return redirect()
            ->route('admin.profile-frames.index')
            ->with('ok', sprintf(
                'Profile frame awards processed. %d granted, %d skipped.',
                (int) ($report['granted_count'] ?? 0),
                (int) ($report['skipped_count'] ?? 0),
            ))
            ->with('frame_awards_report', $report);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $asset = $request->file('asset');
        $thumb = $request->file('thumbnail');
        $assetPath = $asset ? $this->storeAsset($asset) : null;
        $thumbPath = $thumb ? $this->storeAsset($thumb) : $assetPath;

        $frame = ProfileFrame::query()->create([
            'name' => trim((string) $data['name']),
            'slug' => $this->resolveSlug($data['slug'] ?? null, $data['name']),
            'asset_url' => $assetPath,
            'thumbnail_url' => $thumbPath,
            'rarity' => $data['rarity'],
            'category' => $data['category'],
            'unlock_type' => $data['unlock_type'],
            'valid_days' => $data['valid_days'] ? (int) $data['valid_days'] : null,
            'price_coins' => $data['price_coins'] !== null ? (int) $data['price_coins'] : null,
            'sort_order' => (int) ($data['sort_order'] ?? 0),
            'is_active' => $request->boolean('is_active', true),
        ]);

        return redirect()->route('admin.profile-frames.index')->with('ok', "Profile frame '{$frame->name}' created.");
    }

    public function edit(ProfileFrame $profile_frame)
    {
        return view('admin.profile-frames.edit', ['frame' => $profile_frame]);
    }

    public function update(Request $request, ProfileFrame $profile_frame)
    {
        $data = $this->validated($request, $profile_frame);
        $asset = $request->file('asset');
        $thumb = $request->file('thumbnail');

        $nextAsset = $profile_frame->getRawOriginal('asset_url');
        if ($asset) {
            $old = $nextAsset;
            $nextAsset = $this->storeAsset($asset);
            $this->deleteLocalUpload($old);
        }

        $nextThumb = $profile_frame->getRawOriginal('thumbnail_url');
        if ($thumb) {
            $old = $nextThumb;
            $nextThumb = $this->storeAsset($thumb);
            $this->deleteLocalUpload($old);
        } elseif ($asset && !$thumb && $this->isStoredUpload($nextThumb) === false) {
            $nextThumb = $nextAsset;
        }

        $profile_frame->update([
            'name' => trim((string) $data['name']),
            'slug' => $this->resolveSlug($data['slug'] ?? null, $data['name'], $profile_frame),
            'asset_url' => $nextAsset,
            'thumbnail_url' => $nextThumb,
            'rarity' => $data['rarity'],
            'category' => $data['category'],
            'unlock_type' => $data['unlock_type'],
            'valid_days' => $data['valid_days'] ? (int) $data['valid_days'] : null,
            'price_coins' => $data['price_coins'] !== null ? (int) $data['price_coins'] : null,
            'sort_order' => (int) ($data['sort_order'] ?? 0),
            'is_active' => $request->boolean('is_active'),
        ]);

        return redirect()->route('admin.profile-frames.index')->with('ok', "Profile frame '{$profile_frame->name}' updated.");
    }

    public function destroy(ProfileFrame $profile_frame)
    {
        $this->deleteLocalUpload($profile_frame->getRawOriginal('asset_url'));
        $this->deleteLocalUpload($profile_frame->getRawOriginal('thumbnail_url'));
        $profile_frame->delete();

        return redirect()->route('admin.profile-frames.index')->with('ok', 'Profile frame deleted.');
    }

    private function validated(Request $request, ?ProfileFrame $frame = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'slug' => [
                'nullable',
                'string',
                'max:140',
                Rule::unique('profile_frames', 'slug')->ignore($frame?->id),
            ],
            'asset' => [$frame ? 'nullable' : 'required', 'file', 'mimes:png,webp', 'max:4096'],
            'thumbnail' => ['nullable', 'file', 'mimes:png,webp', 'max:4096'],
            'rarity' => ['required', 'string', 'in:common,rare,epic,legendary,mythic'],
            'category' => ['required', 'string', 'max:60'],
            'unlock_type' => ['required', 'string', 'max:60'],
            'valid_days' => ['nullable', 'integer', 'min:1', 'max:3650'],
            'price_coins' => ['nullable', 'integer', 'min:0', 'max:100000000'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'is_active' => ['nullable'],
        ]);
    }

    private function resolveSlug(?string $requested, string $name, ?ProfileFrame $frame = null): string
    {
        $base = Str::slug(trim((string) ($requested ?: $name)));
        $fallbackSlug = Str::slug($name);
        $slug = $base !== '' ? $base : ($fallbackSlug !== '' ? $fallbackSlug : ('frame-' . Str::random(6)));

        $query = ProfileFrame::query()->where('slug', $slug);
        if ($frame) {
            $query->whereKeyNot($frame->id);
        }

        if (!$query->exists()) {
            return $slug;
        }

        $suffix = 2;
        do {
            $candidate = "{$slug}-{$suffix}";
            $exists = ProfileFrame::query()
                ->where('slug', $candidate)
                ->when($frame, fn ($q) => $q->whereKeyNot($frame->id))
                ->exists();
            $suffix++;
        } while ($exists);

        return $candidate;
    }

    private function storeAsset(UploadedFile $file): string
    {
        $stored = $file->storeAs(
            'profile-frames/uploads',
            Str::uuid()->toString() . '.' . strtolower($file->getClientOriginalExtension()),
            'public'
        );

        return $stored;
    }

    private function isStoredUpload(?string $value): bool
    {
        $raw = trim((string) $value);
        return $raw !== '' && Str::startsWith($raw, 'profile-frames/uploads/');
    }

    private function deleteLocalUpload(?string $value): void
    {
        $raw = trim((string) $value);
        if (!$this->isStoredUpload($raw)) {
            return;
        }

        if (Storage::disk('public')->exists($raw)) {
            Storage::disk('public')->delete($raw);
        }
    }
}
