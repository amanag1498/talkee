<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\HostAvailabilityService;
use Illuminate\Http\Request;

class LiveUsersController extends Controller
{
    public function __construct(private HostAvailabilityService $availabilityService)
    {
    }

    public function index(Request $request)
    {
        return response()->json([
            'ok' => true,
            'data' => $this->availabilityService->visibleLiveUsersFor($request->user()),
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
            'data' => $availability,
        ]);
    }

    public function hostStatus(Request $request)
    {
        $user = $request->user();
        abort_unless($user->hasRole('host'), 403, 'Only hosts can view host availability.');

        return response()->json([
            'ok' => true,
            'data' => $this->availabilityService->ensureForUser($user),
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
}
