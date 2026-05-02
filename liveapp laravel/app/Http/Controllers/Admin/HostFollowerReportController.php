<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Host;
use App\Models\HostFollower;
use App\Models\UserNotification;
use Illuminate\Http\Request;

class HostFollowerReportController extends Controller
{
    public function index(Request $request)
    {
        $hostId = $request->integer('host_id');

        $hosts = Host::query()
            ->with('user')
            ->withCount('followers')
            ->orderBy('stage_name')
            ->get();

        $rows = HostFollower::query()
            ->with(['host.user', 'user'])
            ->when($hostId, fn ($query) => $query->where('host_id', $hostId))
            ->latest('id')
            ->paginate(30);

        $topHosts = Host::query()
            ->with('user')
            ->withCount('followers')
            ->orderByDesc('followers_count')
            ->limit(10)
            ->get();

        return view('admin.reports.host-followers', [
            'rows' => $rows,
            'hosts' => $hosts,
            'topHosts' => $topHosts,
            'hostId' => $hostId,
        ]);
    }

    public function destroy(HostFollower $hostFollower)
    {
        $hostFollower->delete();

        return redirect()
            ->route('admin.reports.host-followers')
            ->with('ok', 'Follower relationship removed.');
    }

    public function notifications(Request $request)
    {
        $items = UserNotification::query()
            ->with('user')
            ->whereIn('type', ['host_online', 'host_available'])
            ->latest('id')
            ->paginate(30);

        return view('admin.reports.follow-notifications', [
            'items' => $items,
        ]);
    }
}
