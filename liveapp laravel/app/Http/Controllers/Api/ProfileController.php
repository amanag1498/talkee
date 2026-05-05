<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Host;
use App\Services\HostEarningsReportService;
use App\Services\ProfileFrameService;
use App\Services\ProfileService;
use Illuminate\Http\Request;

class ProfileController extends Controller
{
    public function __construct(
        private ProfileService $profiles,
        private ProfileFrameService $frames,
        private HostEarningsReportService $hostReports,
    )
    {
    }

    public function show(Request $request)
    {
        return response()->json([
            'ok' => true,
            'data' => $this->profiles->payload($request->user()),
        ]);
    }

    public function publicShow(Request $request, User $user)
    {
        return response()->json([
            'ok' => true,
            'data' => $this->profiles->payload(
                $user,
                $request->user(),
                true,
                (($code = (int) $request->header('X-App-Version-Code', 0)) > 0 ? $code : null),
            ),
        ]);
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:120',
            'stage_name' => 'nullable|string|max:120',
            'contact_phone' => 'nullable|string|max:30',
            'country' => 'nullable|string|max:80',
            'city' => 'nullable|string|max:80',
            'bio' => 'nullable|string|max:1000',
        ]);

        $user = $this->profiles->update($request->user(), $data);

        return response()->json([
            'ok' => true,
            'data' => $this->profiles->payload($user),
        ]);
    }

    public function avatar(Request $request)
    {
        $data = $request->validate([
            'avatar' => 'required|image|mimes:jpg,jpeg,png,webp|max:4096',
        ]);

        $user = $this->profiles->updateAvatar($request->user(), $data['avatar']);

        return response()->json([
            'ok' => true,
            'data' => $this->profiles->payload($user),
        ]);
    }

    public function frames(Request $request)
    {
        return response()->json([
            'ok' => true,
            'data' => $this->frames->inventoryPayload($request->user()),
        ]);
    }

    public function shopFrames(Request $request)
    {
        return response()->json([
            'ok' => true,
            'data' => $this->frames->shopPayload($request->user()),
        ]);
    }

    public function equipFrame(Request $request)
    {
        $data = $request->validate([
            'profile_frame_id' => 'required|integer|exists:profile_frames,id',
        ]);

        $equipped = $this->frames->equip($request->user(), (int) $data['profile_frame_id']);
        $user = $request->user()->fresh(['host', 'wallet', 'level']);

        return response()->json([
            'ok' => true,
            'profile_frame' => $equipped,
            'profile' => $this->profiles->payload($user),
            'inventory' => $this->frames->inventoryPayload($user),
        ]);
    }

    public function purchaseFrame(Request $request)
    {
        $data = $request->validate([
            'profile_frame_id' => 'required|integer|exists:profile_frames,id',
        ]);

        $ownership = $this->frames->purchase(
            $request->user(),
            (int) $data['profile_frame_id'],
            $request->header('Idempotency-Key') ?: $request->input('idempotency_key'),
        );

        $user = $request->user()->fresh(['host', 'wallet', 'level']);

        return response()->json([
            'ok' => true,
            'profile_frame' => $this->frames->framePayload($ownership->profileFrame, $ownership),
            'profile' => $this->profiles->payload($user),
            'inventory' => $this->frames->inventoryPayload($user),
            'shop' => $this->frames->shopPayload($user),
        ], 201);
    }

    public function hostEarningsReport(Request $request)
    {
        abort_unless($request->user()->hasRole('host'), 403);

        $host = Host::query()->where('user_id', $request->user()->id)->firstOrFail();

        return response()->json([
            'ok' => true,
            'data' => $this->hostReports->payloadForHost($host),
        ]);
    }
}
