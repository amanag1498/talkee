<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Agency;
use App\Models\AgencyPayoutReport;
use App\Services\AgencyWeeklyPayoutReportService;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AgencyPayoutReportController extends Controller
{
    public function __construct(private AgencyWeeklyPayoutReportService $service)
    {
    }

    public function index(Request $request)
    {
        $reports = AgencyPayoutReport::query()
            ->with(['agency.owner', 'items'])
            ->when($request->filled('agency_id'), fn ($query) => $query->where('agency_id', (int) $request->integer('agency_id')))
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')->toString()))
            ->when($request->filled('week_start'), fn ($query) => $query->whereDate('period_start', $request->date('week_start')->toDateString()))
            ->when($request->filled('date_from'), fn ($query) => $query->whereDate('period_start', '>=', $request->date('date_from')->toDateString()))
            ->when($request->filled('date_to'), fn ($query) => $query->whereDate('period_end', '<=', $request->date('date_to')->toDateString()))
            ->latest('period_start')
            ->paginate(20)
            ->withQueryString();

        $summaryQuery = AgencyPayoutReport::query()
            ->when($request->filled('agency_id'), fn ($query) => $query->where('agency_id', (int) $request->integer('agency_id')))
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')->toString()))
            ->when($request->filled('week_start'), fn ($query) => $query->whereDate('period_start', $request->date('week_start')->toDateString()))
            ->when($request->filled('date_from'), fn ($query) => $query->whereDate('period_start', '>=', $request->date('date_from')->toDateString()))
            ->when($request->filled('date_to'), fn ($query) => $query->whereDate('period_end', '<=', $request->date('date_to')->toDateString()));

        return view('admin.agency-payout-reports.index', [
            'reports' => $reports,
            'agencies' => Agency::query()->orderBy('name')->get(['id', 'name']),
            'statuses' => ['generated', 'pending_review', 'approved', 'paid', 'rejected'],
            'summary' => [
                'reports' => (clone $summaryQuery)->count(),
                'gross_earnings' => (int) (clone $summaryQuery)->sum('gross_earnings'),
                'agency_commission' => (int) (clone $summaryQuery)->sum('agency_commission'),
                'final_payable' => (int) (clone $summaryQuery)->sum('final_payable'),
                'paid' => (clone $summaryQuery)->where('status', 'paid')->count(),
            ],
        ]);
    }

    public function show(AgencyPayoutReport $agency_payout_report)
    {
        $agency_payout_report->load(['agency.owner', 'items.host.user']);

        return view('admin.agency-payout-reports.show', [
            'report' => $agency_payout_report,
        ]);
    }

    public function generate(Request $request)
    {
        $data = $request->validate([
            'start' => 'nullable|date',
            'end' => 'nullable|date',
            'agency_id' => 'nullable|integer|exists:agencies,id',
            'force' => 'nullable|boolean',
        ]);

        try {
            [$start, $end] = $this->service->resolvePeriod(
                $data['start'] ?? null,
                $data['end'] ?? null,
            );

            $result = $this->service->generate(
                periodStart: $start,
                periodEnd: $end,
                agencyId: isset($data['agency_id']) ? (int) $data['agency_id'] : null,
                force: (bool) ($data['force'] ?? false),
                actor: $request->user(),
            );
        } catch (InvalidArgumentException $e) {
            return back()->withInput()->withErrors(['generate' => $e->getMessage()]);
        }

        return redirect()
            ->route('admin.agency-payout-reports.index', [
                'date_from' => $start->toDateString(),
                'date_to' => $end->toDateString(),
                'agency_id' => $data['agency_id'] ?? null,
            ])
            ->with('status', 'Generated ' . $result['generated_count'] . ' payout report(s).');
    }

    public function review(Request $request, AgencyPayoutReport $agency_payout_report)
    {
        $data = $request->validate([
            'deductions' => 'nullable|integer|min:0',
            'admin_remarks' => 'nullable|string|max:5000',
        ]);

        try {
            $this->service->markPendingReview(
                report: $agency_payout_report,
                deductions: (int) ($data['deductions'] ?? 0),
                remarks: $data['admin_remarks'] ?? null,
                actor: $request->user(),
            );
        } catch (InvalidArgumentException $e) {
            return back()->withInput()->withErrors(['review' => $e->getMessage()]);
        }

        return redirect()->route('admin.agency-payout-reports.show', $agency_payout_report)->with('status', 'Report moved to pending review.');
    }

    public function approve(Request $request, AgencyPayoutReport $agency_payout_report)
    {
        $data = $request->validate([
            'deductions' => 'nullable|integer|min:0',
            'admin_remarks' => 'nullable|string|max:5000',
        ]);

        try {
            $this->service->approve(
                report: $agency_payout_report,
                deductions: (int) ($data['deductions'] ?? 0),
                remarks: $data['admin_remarks'] ?? null,
                actor: $request->user(),
            );
        } catch (InvalidArgumentException $e) {
            return back()->withInput()->withErrors(['approve' => $e->getMessage()]);
        }

        return redirect()->route('admin.agency-payout-reports.show', $agency_payout_report)->with('status', 'Report approved.');
    }

    public function reject(Request $request, AgencyPayoutReport $agency_payout_report)
    {
        $data = $request->validate([
            'admin_remarks' => 'required|string|max:5000',
        ]);

        try {
            $this->service->reject(
                report: $agency_payout_report,
                remarks: $data['admin_remarks'],
                actor: $request->user(),
            );
        } catch (InvalidArgumentException $e) {
            return back()->withInput()->withErrors(['reject' => $e->getMessage()]);
        }

        return redirect()->route('admin.agency-payout-reports.show', $agency_payout_report)->with('status', 'Report rejected.');
    }

    public function markPaid(Request $request, AgencyPayoutReport $agency_payout_report)
    {
        $data = $request->validate([
            'admin_remarks' => 'nullable|string|max:5000',
        ]);

        try {
            $this->service->markPaid(
                report: $agency_payout_report,
                remarks: $data['admin_remarks'] ?? null,
                actor: $request->user(),
            );
        } catch (InvalidArgumentException $e) {
            return back()->withInput()->withErrors(['mark_paid' => $e->getMessage()]);
        }

        return redirect()->route('admin.agency-payout-reports.show', $agency_payout_report)->with('status', 'Report marked as paid.');
    }

    public function export(AgencyPayoutReport $agency_payout_report): StreamedResponse
    {
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
        }, 'agency-payout-report-' . $agency_payout_report->id . '.csv');
    }
}
