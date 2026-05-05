<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Gift;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class GiftAdminController extends Controller
{
    public function index(Request $request)
    {
        $q = Gift::query()->orderBy('sort_order')->orderBy('id','desc');

        if ($s = $request->string('s')->trim()) {
            $q->where('name','like',"%{$s}%");
        }
        if ($request->filled('active')) {
            $q->where('is_active', (bool) $request->boolean('active'));
        }

        $gifts = $q->paginate(20);
        return view('admin.gifts.index', compact('gifts'));
    }

    public function create()
    {
        return view('admin.gifts.create', [
            'giftTypes' => Gift::GIFT_TYPES,
            'animationTiers' => Gift::ANIMATION_TIERS,
        ]);
    }

    public function store(Request $request)
    {
        $this->assertUploadReady($request, 'gift_file');

        $data = $request->validate([
            'name'       => 'required|string|max:120',
            'coins'      => 'required|integer|min:1',
            'gift_type'  => 'nullable|in:'.implode(',', Gift::GIFT_TYPES),
            'animation_tier' => 'nullable|in:'.implode(',', Gift::ANIMATION_TIERS),
            'animation_duration_ms' => 'nullable|integer|min:800|max:12000',
            'is_active'  => 'nullable|boolean',
            'sort_order' => 'nullable|integer|min:0|max:100000',
            'gift_file'  => 'required|file|max:102400',
        ], [
            'gift_file.required' => 'Please choose a gift asset file.',
            'gift_file.max' => 'The gift file must be 100 MB or smaller.',
            'gift_file.uploaded' => 'The gift file could not be uploaded. Check PHP upload_max_filesize, post_max_size, and nginx client_max_body_size.',
        ]);
        $giftFile = $request->file('gift_file');
        $this->assertSupportedGiftFile($giftFile);
        $data['is_active'] = $request->boolean('is_active');
        $data['gift_url'] = $this->storeGiftFile($giftFile);
        $data['gift_type'] = $this->resolveGiftType($data['gift_type'] ?? null, $giftFile);
        $data['animation_tier'] = $data['animation_tier'] ?? null;
        $data['animation_duration_ms'] = $data['animation_duration_ms'] ?? null;
        Gift::create($data);

        return redirect()->route('admin.gifts.index')->with('ok','Gift created.');
    }

    public function edit(Gift $gift)
    {
        return view('admin.gifts.edit', [
            'gift' => $gift,
            'giftTypes' => Gift::GIFT_TYPES,
            'animationTiers' => Gift::ANIMATION_TIERS,
        ]);
    }

    public function update(Request $request, Gift $gift)
    {
        $this->assertUploadReady($request, 'gift_file');

        $data = $request->validate([
            'name'       => 'required|string|max:120',
            'coins'      => 'required|integer|min:1',
            'gift_type'  => 'nullable|in:'.implode(',', Gift::GIFT_TYPES),
            'animation_tier' => 'nullable|in:'.implode(',', Gift::ANIMATION_TIERS),
            'animation_duration_ms' => 'nullable|integer|min:800|max:12000',
            'is_active'  => 'nullable|boolean',
            'sort_order' => 'nullable|integer|min:0|max:100000',
            'gift_file'  => 'nullable|file|max:102400',
        ], [
            'gift_file.max' => 'The gift file must be 100 MB or smaller.',
            'gift_file.uploaded' => 'The gift file could not be uploaded. Check PHP upload_max_filesize, post_max_size, and nginx client_max_body_size.',
        ]);
        $giftFile = $request->file('gift_file');
        if ($giftFile) {
            $this->assertSupportedGiftFile($giftFile);
        }
        $data['is_active'] = $request->boolean('is_active');
        if ($giftFile) {
            $previous = (string) $gift->getRawOriginal('gift_url');
            $data['gift_url'] = $this->storeGiftFile($giftFile);
            $this->deleteLocalAsset($previous);
        }
        $data['gift_type'] = $this->resolveGiftType(
            $data['gift_type'] ?? null,
            $giftFile,
            fallback: $gift->gift_type,
        );
        $data['animation_tier'] = $data['animation_tier'] ?? null;
        $data['animation_duration_ms'] = $data['animation_duration_ms'] ?? null;
        $gift->update($data);

        return redirect()->route('admin.gifts.index')->with('ok','Gift updated.');
    }

    public function destroy(Gift $gift)
    {
        $this->deleteLocalAsset((string) $gift->getRawOriginal('gift_url'));
        $gift->delete();
        return back()->with('ok','Gift deleted.');
    }

    private function assertSupportedGiftFile(?UploadedFile $file): void
    {
        if (!$file) {
            return;
        }

        $extension = strtolower($file->getClientOriginalExtension());
        if (!in_array($extension, ['svg', 'svga', 'gif', 'png', 'jpg', 'jpeg', 'webp'], true)) {
            throw ValidationException::withMessages([
                'gift_file' => 'Gift asset must be SVG, SVGA, GIF, PNG, JPG, JPEG, or WEBP.',
            ]);
        }
    }

    private function assertUploadReady(Request $request, string $field): void
    {
        $fileMeta = $_FILES[$field] ?? null;
        if (!is_array($fileMeta)) {
            return;
        }

        $error = $fileMeta['error'] ?? null;
        if (!is_int($error) || $error === UPLOAD_ERR_OK || $error === UPLOAD_ERR_NO_FILE) {
            return;
        }

        throw ValidationException::withMessages([
            $field => $this->uploadErrorMessage($error),
        ]);
    }

    private function uploadErrorMessage(int $error): string
    {
        return match ($error) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'The gift file is too large for the server upload limit. Increase PHP upload_max_filesize and post_max_size, and increase nginx client_max_body_size.',
            UPLOAD_ERR_PARTIAL => 'The gift file upload was interrupted. Please try again.',
            UPLOAD_ERR_NO_TMP_DIR => 'The server temporary upload directory is missing.',
            UPLOAD_ERR_CANT_WRITE => 'The server could not write the uploaded gift file to disk.',
            UPLOAD_ERR_EXTENSION => 'A server extension stopped the gift file upload.',
            default => 'The gift file could not be uploaded. Please try again.',
        };
    }

    private function resolveGiftType(
        ?string $requestedType,
        ?UploadedFile $file,
        ?string $fallback = null,
    ): ?string {
        $normalized = strtolower(trim((string) $requestedType));
        if ($normalized !== '' && $normalized !== 'auto') {
            return $normalized;
        }

        if ($file) {
            return match (strtolower($file->getClientOriginalExtension())) {
                'svg' => 'svg',
                'svga' => 'svga',
                'gif' => 'gif',
                default => 'image',
            };
        }

        return $fallback;
    }

    private function storeGiftFile(UploadedFile $file): string
    {
        return $file->storeAs(
            'gifts',
            Str::uuid()->toString().'.'.strtolower($file->getClientOriginalExtension()),
            'public',
        );
    }

    private function deleteLocalAsset(?string $value): void
    {
        $raw = trim((string) $value);
        if ($raw === '' || Str::startsWith($raw, ['http://', 'https://'])) {
            return;
        }

        $path = Str::startsWith($raw, '/storage/')
            ? ltrim(Str::after($raw, '/storage/'), '/')
            : ltrim($raw, '/');

        if ($path !== '' && Storage::disk('public')->exists($path)) {
            Storage::disk('public')->delete($path);
        }
    }
}
