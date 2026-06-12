<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Host;
use App\Services\HostAvailabilityService;
use Illuminate\Http\Request;

class LiveUsersController extends Controller
{
    public function __construct(private HostAvailabilityService $availabilityService)
    {
    }

    public function index(Request $request)
    {
        $perPage = max(1, min((int) $request->integer('per_page', 50), 100));
        $page = max(1, (int) $request->integer('page', 1));
        $result = $this->availabilityService->visibleLiveUsersFor($request->user(), $page, $perPage);
        $payload = $result->getCollection();

        return response()->json([
            'ok' => true,
            'data' => $payload,
            'meta' => [
                'current_page' => $result->currentPage(),
                'per_page' => $result->perPage(),
                'has_more' => $result->hasMorePages(),
                'total' => $result->total(),
                'last_page' => $result->lastPage(),
            ],
        ]);
    }

    public function toggleHostStatus(Request $request)
    {
        $user = $request->user();
        abort_unless($user->hasRole('host'), 403, 'Only hosts can toggle availability.');

        $data = $request->validate([
            'manual_status' => 'required|in:online,offline',
        ]);

        $availability = $this->availabilityService->toggleManualStatus($user, $data['manual_status']);

        return response()->json([
            'ok' => true,
            'data' => $this->statusPayload($user->id, $availability),
        ]);
    }

    public function hostStatus(Request $request)
    {
        $user = $request->user();
        abort_unless($user->hasRole('host'), 403, 'Only hosts can view host availability.');

        $availability = $this->availabilityService->ensureForUser($user);
        if ($this->availabilityService->isHostBlocked($user)) {
            $availability->forceFill([
                'manual_status' => 'offline',
                'socket_status' => 'offline',
            ]);
        }

        return response()->json([
            'ok' => true,
            'data' => $this->statusPayload($user->id, $availability),
        ]);
    }

    public function socketStatus(Request $request)
    {
        $data = $request->validate([
            'socket_status' => 'required|in:online,offline',
        ]);

        $availability = $this->availabilityService->updateSocketStatus($request->user()->id, $data['socket_status']);

        return response()->json([
            'ok' => true,
            'data' => $availability,
        ]);
    }

    private function statusPayload(int $userId, mixed $availability): array
    {
        $host = Host::query()->where('user_id', $userId)->first();
        $hostUserBlocked = (bool) optional($host?->user)->is_blocked;
        $hostBlocked = (bool) ($host?->is_blocked ?? false);

        return array_merge($availability->toArray(), [
            'is_blocked' => $hostUserBlocked,
            'host_is_blocked' => $hostBlocked,
            'video_rooms_enabled' => (bool) ($host?->video_rooms_enabled ?? true),
            'audio_rooms_enabled' => (bool) ($host?->audio_rooms_enabled ?? true),
            'video_calls_enabled' => (bool) ($host?->video_calls_enabled ?? true),
            'audio_calls_enabled' => (bool) ($host?->audio_calls_enabled ?? true),
        ]);
    }
}
