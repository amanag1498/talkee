<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Host;
use App\Services\HostEarningsReportService;
use App\Services\ProfileService;
use Illuminate\Http\Request;

class ProfileController extends Controller
{
    public function __construct(
        private ProfileService $profiles,
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
