<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Agency;
use App\Models\Host;
use App\Services\NotifyUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class HostAdminController extends Controller
{
    public function index(Request $request)
    {
        $q = Host::query()->with(['user', 'agency'])->withCount(['photos', 'followers']);
        if ($s = $request->get('s')) {
            $q->where(function($qq) use ($s) {
                $qq->where('stage_name','like',"%$s%")
                   ->orWhereHas('user', fn($u)=>$u
                        ->where('name','like',"%$s%")
                        ->orWhere('email','like',"%$s%"));
            });
        }
        $hosts = $q->latest()->paginate(20);
        return view('admin.hosts.index', compact('hosts'));
    }

    public function edit(Host $host)
    {
        $host->load(['photos','user','agency','followers.user']);
        $agencies = Agency::query()->orderBy('name')->get(['id', 'name']);
        return view('admin.hosts.edit', compact('host', 'agencies'));
    }

    public function update(Request $request, Host $host)
    {
        $data = $request->validate([
            'stage_name'        => 'nullable|string|max:255',
            'bio'               => 'nullable|string|max:2000',
            'payout_percentage' => 'nullable|numeric|min:0|max:100',
            'weekly_bonus'      => 'nullable|integer|min:0',
            'agency_id'         => 'nullable|exists:agencies,id',
            'video_rooms_enabled' => 'nullable|boolean',
            'audio_rooms_enabled' => 'nullable|boolean',
            'video_calls_enabled' => 'nullable|boolean',
            'audio_calls_enabled' => 'nullable|boolean',
            'audio_call_rate_per_minute' => 'nullable|integer|min:1|max:100000',
            'video_call_rate_per_minute' => 'nullable|integer|min:1|max:100000',
            'goal_followers' => ['nullable', 'string', 'regex:/^\s*\d+(\s*,\s*\d+)*\s*$/'],
            'goal_weekly_live_minutes' => ['nullable', 'string', 'regex:/^\s*\d+(\s*,\s*\d+)*\s*$/'],
            'goal_weekly_gifted_coins' => ['nullable', 'string', 'regex:/^\s*\d+(\s*,\s*\d+)*\s*$/'],
            // photos: optional; if present, replace existing set (max 6)
            'photos'            => 'sometimes|array|max:6',
            'photos.*'          => 'file|image|max:4096', // 4MB each
        ]);

        DB::transaction(function() use ($host, $data, $request) {
            $host->update([
                'stage_name'        => $data['stage_name']        ?? $host->stage_name,
                'bio'               => $data['bio']               ?? $host->bio,
                'payout_percentage' => (float)($data['payout_percentage'] ?? $host->payout_percentage ?? 0),
                'weekly_bonus'      => (int)  ($data['weekly_bonus']      ?? $host->weekly_bonus      ?? 0),
                'agency_id'         => array_key_exists('agency_id', $data) ? $data['agency_id'] : $host->agency_id,
                'video_rooms_enabled' => $request->boolean('video_rooms_enabled', $host->video_rooms_enabled),
                'audio_rooms_enabled' => $request->boolean('audio_rooms_enabled', $host->audio_rooms_enabled),
                'video_calls_enabled' => $request->boolean('video_calls_enabled', $host->video_calls_enabled),
                'audio_calls_enabled' => $request->boolean('audio_calls_enabled', $host->audio_calls_enabled),
                'audio_call_rate_per_minute' => array_key_exists('audio_call_rate_per_minute', $data)
                    ? $data['audio_call_rate_per_minute']
                    : $host->audio_call_rate_per_minute,
                'video_call_rate_per_minute' => array_key_exists('video_call_rate_per_minute', $data)
                    ? $data['video_call_rate_per_minute']
                    : $host->video_call_rate_per_minute,
                'goal_followers' => $this->normalizeGoalList($data['goal_followers'] ?? null),
                'goal_weekly_live_minutes' => $this->normalizeGoalList($data['goal_weekly_live_minutes'] ?? null),
                'goal_weekly_gifted_coins' => $this->normalizeGoalList($data['goal_weekly_gifted_coins'] ?? null),
            ]);

            // If new photos uploaded, replace full set (keeps it simple/clean)
            if ($request->hasFile('photos')) {
                // delete old files (optional—comment out if you want to keep files on disk)
                foreach ($host->photos as $p) {
                    if ($p->path && Storage::disk('public')->exists($p->path)) {
                        Storage::disk('public')->delete($p->path);
                    }
                }
                $host->photos()->delete();

                $paths = [];
                $i = 0;
                foreach ($request->file('photos') as $file) {
                    if ($i >= 6) break;
                    $stored = $file->store("hosts/{$host->id}", 'public'); // -> storage/app/public/hosts/{id}/...
                    $paths[] = $stored;
                    $i++;
                }
                $host->syncPhotos($paths);
            }
        });

        return redirect()->route('admin.hosts.index')->with('ok','Host updated.');
    }

    public function block(Host $host)
    {
        $host->update(['is_blocked'=>true]);
        try {
        NotifyUser::send((int)$host->user_id, [
            'type'   => 'host_blocked',
            'title'  => 'Account status changed',
            'body'   => 'Your host account has been blocked by admin.',
            'meta'   => ['host_id'=>$host->id],
            'screen' => 'notifications',
        ], ['push'=>true,'persist'=>true]);
    } catch (\Throwable $e) {}

        return back()->with('ok','Host blocked.');
    }

    public function unblock(Host $host)
    {
        $host->update(['is_blocked'=>false]);
        try {
        NotifyUser::send((int)$host->user_id, [
            'type'   => 'host_unblocked',
            'title'  => 'Account status changed',
            'body'   => 'Your host account has been unblocked.',
            'meta'   => ['host_id'=>$host->id],
            'screen' => 'notifications',
        ], ['push'=>true,'persist'=>true]);
    } catch (\Throwable $e) {}

        return back()->with('ok','Host unblocked.');
    }

    private function normalizeGoalList(?string $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        $parts = preg_split('/\s*,\s*/', $value) ?: [];
        $normalized = collect($parts)
            ->map(fn ($part) => (int) $part)
            ->filter(fn (int $number) => $number > 0)
            ->unique()
            ->sort()
            ->values()
            ->all();

        return empty($normalized) ? null : implode(',', $normalized);
    }
}
