<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\CallReportService;
use Illuminate\Http\Request;

class CallReportController extends Controller
{
    public function __construct(private CallReportService $reportService)
    {
    }

    public function index(Request $request)
    {
        $report = $this->reportService->forAdmin($request);

        return view('admin.calls.index', [
            'report' => $report,
            'scopeLabel' => 'All Calls',
            'exportRoute' => route('admin.calls.export', $request->query()),
            'tabs' => [
                'all' => 'All Calls',
                'active' => 'Active Calls',
                'completed' => 'Completed Calls',
                'missed_rejected' => 'Missed/Rejected Calls',
                'host_earnings' => 'Host Earnings',
                'agency_earnings' => 'Agency Earnings',
            ],
        ]);
    }

    public function export(Request $request)
    {
        abort_unless($this->reportService->schemaReady(), 409, 'Call reporting tables are not available yet. Run php artisan migrate first.');

        $rows = $this->reportService->baseQuery($request)->with(['caller', 'receiver', 'host.user', 'agency'])->get();

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['id', 'caller', 'receiver', 'host', 'host_user_id', 'agency', 'type', 'status', 'end_reason', 'coin_rate_per_minute', 'duration_seconds', 'billable_minutes', 'coins_charged', 'host_earning', 'agency_earning', 'platform_earning', 'created_at']);
            foreach ($rows as $call) {
                fputcsv($out, [
                    $call->id,
                    $call->caller?->name,
                    $call->receiver?->name,
                    $call->host?->user?->name,
                    $call->host?->user_id,
                    $call->agency?->name,
                    $call->type,
                    $call->status,
                    $call->end_reason,
                    $call->coin_rate_per_minute,
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
        }, 'admin-calls-' . now()->format('Ymd_His') . '.csv');
    }
}
