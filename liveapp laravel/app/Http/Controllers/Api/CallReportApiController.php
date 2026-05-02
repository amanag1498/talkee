<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Agency;
use App\Models\Host;
use App\Services\CallReportService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CallReportApiController extends Controller
{
    public function __construct(private CallReportService $reportService)
    {
    }

    public function adminCalls(Request $request)
    {
        abort_unless($request->user()->hasRole('admin'), 403);
        return $this->respondReport($this->reportService->forAdmin($request));
    }

    public function adminSummary(Request $request)
    {
        abort_unless($request->user()->hasRole('admin'), 403);
        $report = $this->reportService->forAdmin($request);
        return response()->json(['ok' => true, 'data' => $report['summary']]);
    }

    public function adminExport(Request $request): StreamedResponse
    {
        abort_unless($request->user()->hasRole('admin'), 403);
        abort_unless($this->reportService->schemaReady(), 409, 'Call reporting tables are not available yet. Run php artisan migrate first.');

        return $this->csvResponse(
            $this->reportService->baseQuery($request)->with(['caller', 'receiver', 'host.user', 'agency'])->get()->all(),
            'admin-calls'
        );
    }

    public function agencyCalls(Request $request)
    {
        abort_unless($request->user()->hasRole('agency'), 403);
        $agency = Agency::where('owner_user_id', $request->user()->id)->firstOrFail();
        return $this->respondReport($this->reportService->forAgency($request, $agency));
    }

    public function agencySummary(Request $request)
    {
        abort_unless($request->user()->hasRole('agency'), 403);
        $agency = Agency::where('owner_user_id', $request->user()->id)->firstOrFail();
        return response()->json(['ok' => true, 'data' => $this->reportService->forAgency($request, $agency)['summary']]);
    }

    public function agencyExport(Request $request): StreamedResponse
    {
        abort_unless($request->user()->hasRole('agency'), 403);
        $agency = Agency::where('owner_user_id', $request->user()->id)->firstOrFail();
        abort_unless($this->reportService->schemaReady(), 409, 'Call reporting tables are not available yet. Run php artisan migrate first.');

        return $this->csvResponse(
            $this->reportService->baseQuery($request)
                ->with(['caller', 'receiver', 'host.user', 'agency'])
                ->where('agency_id', $agency->id)
                ->get()
                ->all(),
            'agency-calls'
        );
    }

    public function hostCalls(Request $request)
    {
        abort_unless($request->user()->hasRole('host'), 403);
        $host = Host::where('user_id', $request->user()->id)->firstOrFail();
        return $this->respondReport($this->reportService->forHost($request, $host));
    }

    public function hostSummary(Request $request)
    {
        abort_unless($request->user()->hasRole('host'), 403);
        $host = Host::where('user_id', $request->user()->id)->firstOrFail();
        return response()->json(['ok' => true, 'data' => $this->reportService->forHost($request, $host)['summary']]);
    }

    private function respondReport(array $report)
    {
        $calls = $report['calls'];

        return response()->json([
            'ok' => true,
            'data' => [
                'items' => $calls->items(),
                'pagination' => [
                    'current_page' => $calls->currentPage(),
                    'last_page' => $calls->lastPage(),
                    'total' => $calls->total(),
                ],
                'summary' => $report['summary'],
            ],
        ]);
    }

    private function csvResponse(array $rows, string $prefix): StreamedResponse
    {
        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['id', 'caller_id', 'receiver_id', 'host_id', 'agency_id', 'type', 'status', 'end_reason', 'duration_seconds', 'billable_minutes', 'total_coins_charged', 'host_earning', 'agency_earning', 'platform_earning', 'created_at']);
            foreach ($rows as $row) {
                fputcsv($out, [
                    $row->id,
                    $row->caller_id,
                    $row->receiver_id,
                    $row->host_id,
                    $row->agency_id,
                    $row->type,
                    $row->status,
                    $row->end_reason,
                    $row->duration_seconds,
                    $row->billable_minutes,
                    $row->total_coins_charged,
                    $row->host_earning,
                    $row->agency_earning,
                    $row->platform_earning,
                    optional($row->created_at)?->toDateTimeString(),
                ]);
            }
            fclose($out);
        }, $prefix . '-' . now()->format('Ymd_His') . '.csv');
    }
}
