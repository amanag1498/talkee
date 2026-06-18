<?php
namespace App\Http\Controllers\Agency;

use App\Http\Controllers\Controller;
use App\Models\Agency;
use Illuminate\Http\Request;
use App\Services\AgencyDashboardService;
use App\Services\AgencyWalletService;

class DashboardController extends Controller
{
    public function __construct(
        private AgencyDashboardService $dashboard,
        private AgencyWalletService $wallets,
    )
    {
    }

    public function index(Request $request)
    {
        $agency = Agency::where('owner_user_id', $request->user()->id)->first();
        $filters = $request->only(['period', 'from', 'to']);
        $dashboard = $agency ? $this->dashboard->build($agency, 20, $filters) : null;
        if ($dashboard && isset($dashboard['hosts'])) {
            $dashboard['hosts']->appends($request->query());
        }
        $walletSummary = $agency ? $this->wallets->summary($agency) : null;

        $callsRoute = route('agency.calls.index');
        $payoutReportsRoute = route('agency.payout-reports.index');

        return view('agency.dashboard', compact('agency', 'dashboard', 'walletSummary', 'callsRoute', 'payoutReportsRoute'));
    }
}
