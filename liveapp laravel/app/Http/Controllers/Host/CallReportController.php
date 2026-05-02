<?php

namespace App\Http\Controllers\Host;

use App\Http\Controllers\Controller;
use App\Models\Host;
use App\Services\CallReportService;
use Illuminate\Http\Request;

class CallReportController extends Controller
{
    public function __construct(private CallReportService $reportService)
    {
    }

    public function index(Request $request)
    {
        $host = Host::where('user_id', $request->user()->id)->firstOrFail();
        $report = $this->reportService->forHost($request, $host);

        return view('host.calls.index', [
            'report' => $report,
            'scopeLabel' => 'My Calls',
            'tabs' => [
                'all' => 'My Calls',
                'host_earnings' => 'My Earnings',
            ],
        ]);
    }
}
