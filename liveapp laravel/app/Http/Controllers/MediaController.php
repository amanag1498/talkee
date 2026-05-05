<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Storage;

class MediaController extends Controller
{
    public function avatar(string $path)
    {
        abort_unless(str_starts_with($path, 'avatars/'), 404);
        abort_unless(Storage::disk('public')->exists($path), 404);

        return Storage::disk('public')->response($path);
    }

    public function profileFrame(string $path)
    {
        abort_unless(str_starts_with($path, 'profile-frames/'), 404);

        if (Storage::disk('public')->exists($path)) {
            return Storage::disk('public')->response($path);
        }

        $fullPath = public_path($path);
        abort_unless(is_file($fullPath), 404);

        return response()->file($fullPath);
    }

    public function gift(string $path)
    {
        abort_unless(str_starts_with($path, 'gifts/'), 404);
        abort_unless(Storage::disk('public')->exists($path), 404);

        return Storage::disk('public')->response($path, null, [
            'Content-Type' => $this->mediaContentType($path),
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }

    public function entryPack(string $path)
    {
        abort_unless(str_starts_with($path, 'entry-packs/'), 404);
        abort_unless(Storage::disk('public')->exists($path), 404);

        return Storage::disk('public')->response($path, null, [
            'Content-Type' => $this->mediaContentType($path),
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }

    private function mediaContentType(string $path): string
    {
        $lower = strtolower($path);

        return match (true) {
            str_ends_with($lower, '.svga') => 'application/octet-stream',
            str_ends_with($lower, '.svg') => 'image/svg+xml',
            str_ends_with($lower, '.gif') => 'image/gif',
            str_ends_with($lower, '.webp') => 'image/webp',
            str_ends_with($lower, '.jpg'), str_ends_with($lower, '.jpeg') => 'image/jpeg',
            str_ends_with($lower, '.png') => 'image/png',
            default => Storage::disk('public')->mimeType($path) ?: 'application/octet-stream',
        };
    }
}
