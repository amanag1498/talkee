<?php

namespace App\Http\Controllers\Agency;

use App\Http\Controllers\Controller;
use App\Models\Agency;
use App\Services\CallReportService;
use Illuminate\Http\Request;

class CallReportController extends Controller
{
    public function __construct(private CallReportService $reportService)
    {
    }

    public function index(Request $request)
    {
        $agency = Agency::where('owner_user_id', $request->user()->id)->firstOrFail();
        $report = $this->reportService->forAgency($request, $agency);

        return view('agency.calls.index', [
            'report' => $report,
            'scopeLabel' => 'Calls to My Hosts',
            'exportRoute' => route('agency.calls.export', $request->query()),
            'tabs' => [
                'all' => 'Calls to My Hosts',
                'active' => 'Active Calls',
                'completed' => 'Completed Calls',
                'host_earnings' => 'Host-wise Earnings',
            ],
        ]);
    }

    public function export(Request $request)
    {
        $agency = Agency::where('owner_user_id', $request->user()->id)->firstOrFail();
        abort_unless($this->reportService->schemaReady(), 409, 'Call reporting tables are not available yet. Run php artisan migrate first.');

        $rows = $this->reportService->baseQuery($request)
            ->with(['caller', 'receiver', 'host.user', 'agency'])
            ->where('agency_id', $agency->id)
            ->get();

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['id', 'caller', 'receiver', 'host', 'agency', 'type', 'status', 'end_reason', 'duration_seconds', 'billable_minutes', 'coins_charged', 'host_earning', 'agency_earning', 'platform_earning', 'created_at']);
            foreach ($rows as $call) {
                fputcsv($out, [
                    $call->id,
                    $call->caller?->name,
                    $call->receiver?->name,
                    $call->host?->user?->name,
                    $call->agency?->name,
                    $call->type,
                    $call->status,
                    $call->end_reason,
                    $call->duration_seconds,
                    $call->billable_minutes,
                    $call->total_coins_charged,
                    $call->host_earning,
                    $call->agency_earning,
                    $call->platform_earning,
                    optional($call->created_at)?->toDateTimeString(),
                ]);
            }
            fclose($out);
        }, 'agency-calls-' . now()->format('Ymd_His') . '.csv');
    }
}
