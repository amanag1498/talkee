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
        $dashboard = $agency ? $this->dashboard->build($agency) : null;
        $walletSummary = $agency ? $this->wallets->summary($agency) : null;

        $callsRoute = route('agency.calls.index');
        $payoutReportsRoute = route('agency.payout-reports.index');

        return view('agency.dashboard', compact('agency', 'dashboard', 'walletSummary', 'callsRoute', 'payoutReportsRoute'));
    }
}
