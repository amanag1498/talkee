<?php

namespace App\Http\Controllers\Agency;

use App\Http\Controllers\Controller;
use App\Models\Agency;
use App\Models\AgencyPayoutReport;
use App\Services\AgencyWeeklyPayoutReportService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PayoutReportController extends Controller
{
    private const VISIBLE_STATUSES = ['approved', 'paid'];

    public function __construct(private AgencyWeeklyPayoutReportService $service)
    {
    }

    public function index(Request $request)
    {
        $agency = Agency::query()->where('owner_user_id', $request->user()->id)->firstOrFail();

        $reports = AgencyPayoutReport::query()
            ->with('items')
            ->where('agency_id', $agency->id)
            ->whereIn('status', self::VISIBLE_STATUSES)
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')->toString()))
            ->when($request->filled('week_start'), fn ($query) => $query->whereDate('period_start', $request->date('week_start')->toDateString()))
            ->latest('period_start')
            ->paginate(20)
            ->withQueryString();

        return view('agency.payout-reports.index', [
            'agency' => $agency,
            'reports' => $reports,
            'statuses' => self::VISIBLE_STATUSES,
        ]);
    }

    public function show(Request $request, AgencyPayoutReport $agency_payout_report)
    {
        $agency = Agency::query()->where('owner_user_id', $request->user()->id)->firstOrFail();
        abort_unless((int) $agency_payout_report->agency_id === (int) $agency->id, 403);
        abort_unless(in_array($agency_payout_report->status, self::VISIBLE_STATUSES, true), 404);

        $agency_payout_report->load(['agency.owner', 'items.host.user']);

        return view('agency.payout-reports.show', [
            'agency' => $agency,
            'report' => $agency_payout_report,
        ]);
    }

    public function export(Request $request, AgencyPayoutReport $agency_payout_report): StreamedResponse
    {
        $agency = Agency::query()->where('owner_user_id', $request->user()->id)->firstOrFail();
        abort_unless((int) $agency_payout_report->agency_id === (int) $agency->id, 403);
        abort_unless(in_array($agency_payout_report->status, self::VISIBLE_STATUSES, true), 404);

        $rows = $this->service->exportRows($agency_payout_report);

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fputcsv($out, array_keys($rows[0] ?? [
                'report_id' => null,
                'agency' => null,
                'period_start' => null,
                'period_end' => null,
                'host_id' => null,
                'host_name' => null,
                'call_earnings' => null,
                'call_count' => null,
                'completed_call_count' => null,
                'billable_minutes' => null,
                'video_call_minutes' => null,
                'video_call_gross' => null,
                'audio_call_minutes' => null,
                'audio_call_gross' => null,
                'gift_earnings' => null,
                'gift_events' => null,
                'gift_quantity' => null,
                'unique_gifters' => null,
                'live_room_count' => null,
                'audio_room_count' => null,
                'video_room_count' => null,
                'audio_room_minutes' => null,
                'video_room_minutes' => null,
                'video_gift_gross' => null,
                'audio_gift_gross' => null,
                'pk_earnings' => null,
                'pk_event_count' => null,
                'gross_earnings' => null,
                'agency_commission' => null,
                'agency_payout_percentage' => null,
                'agency_payout' => null,
                'host_share' => null,
                'host_payout_percentage' => null,
                'host_payout' => null,
                'total_payout' => null,
                'final_payable' => null,
                'report_status' => null,
            ]));
            foreach ($rows as $row) {
                fputcsv($out, $row);
            }
            fclose($out);
        }, 'my-agency-payout-report-' . $agency_payout_report->id . '.csv');
    }
}
