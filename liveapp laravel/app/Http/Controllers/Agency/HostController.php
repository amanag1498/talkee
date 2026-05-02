<?php

namespace App\Http\Controllers\Agency;

use App\Http\Controllers\Controller;
use App\Models\Agency;
use App\Models\Host;
use App\Services\AgencyDashboardService;
use Illuminate\Http\Request;

class HostController extends Controller
{
    public function __construct(private AgencyDashboardService $dashboard)
    {
    }

    public function index(Request $request)
    {
        $agency = Agency::where('owner_user_id', $request->user()->id)->firstOrFail();
        $dashboard = $this->dashboard->build($agency, 25);

        return view('agency.hosts.index', [
            'agency' => $agency,
            'hosts' => $dashboard['hosts'],
            'summary' => $dashboard['summary'],
        ]);
    }

    public function show(Request $request, Host $host)
    {
        $agency = Agency::where('owner_user_id', $request->user()->id)->firstOrFail();
        abort_unless((int) $host->agency_id === (int) $agency->id, 404);

        $host->load(['user', 'user.hostAvailability']);
        $detail = $this->dashboard->hostDetail($agency, $host);

        return view('agency.hosts.show', [
            'agency' => $agency,
            'host' => $host,
            'detail' => $detail,
        ]);
    }
}
