<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AgencyRequest;
use App\Models\HostRequest;
use App\Models\HostEnrollRequest;
use App\Models\User;
use App\Models\Agency;
use App\Models\AgencyWallet;
use App\Models\Host;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Support\OpsMetrics;

class DashboardController extends Controller
{
    public function __invoke()
    {
        $stats = [
            'pendingAgency' => AgencyRequest::where('status', 'pending')->count(),
            'pendingHost'   => HostRequest::where('status', 'pending')->count(),
            'pendingEnroll' => HostEnrollRequest::where('status', 'pending')->count(),
            'totalUsers'    => User::count(),
            'totalAgencies' => Agency::count(),
            'totalHosts'    => Host::count(),
            'blockedUsers'  => User::where('is_blocked', true)->count(),
            'userCoinSupply' => (int) Wallet::sum('balance'),
            'agencyCoinSupply' => (int) AgencyWallet::sum('balance'),
        ];
        $stats['coinSupply'] = $stats['userCoinSupply'] + $stats['agencyCoinSupply'];

        $latestAgency = AgencyRequest::with('user')->latest()->limit(5)->get();
        $latestHost   = HostRequest::with('user')->latest()->limit(5)->get();
        $latestEnroll = HostEnrollRequest::with(['hostUser', 'agency'])->latest()->limit(5)->get();

        $latestTx = WalletTransaction::with('wallet.user')->latest()->limit(8)->get();
        $opsMetrics = OpsMetrics::snapshot();
        $healthConfig = [
            'liveEndpoint' => url('/api/health/live'),
            'readyEndpoint' => url('/api/health/ready'),
            'metricsEndpoint' => url('/api/metrics'),
            'metricsHeader' => 'X-Metrics-Key',
            'metricsKeyConfigured' => filled((string) config('ops.metrics.key', '')),
            'exposeErrors' => (bool) config('ops.health.expose_errors', false),
        ];

        return view('admin.dashboard', compact('stats', 'latestAgency', 'latestHost', 'latestEnroll', 'latestTx', 'opsMetrics', 'healthConfig'));
    }
}
