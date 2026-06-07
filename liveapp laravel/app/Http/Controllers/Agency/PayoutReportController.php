<?php

namespace App\Http\Controllers\Agency;

use App\Http\Controllers\Controller;
use App\Models\Agency;
use App\Models\AgencyPayoutReport;
use App\Services\AgencyWeeklyPayoutReportService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;

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
            ->whereNotNull('published_at')
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
        abort_unless($agency_payout_report->published_at !== null, 404);

        $agency_payout_report->load(['agency.owner', 'items.host.user']);

        return view('agency.payout-reports.show', [
            'agency' => $agency,
            'report' => $agency_payout_report,
        ]);
    }

    public function export(Request $request, AgencyPayoutReport $agency_payout_report)
    {
        $agency = Agency::query()->where('owner_user_id', $request->user()->id)->firstOrFail();
        abort_unless((int) $agency_payout_report->agency_id === (int) $agency->id, 403);
        abort_unless(in_array($agency_payout_report->status, self::VISIBLE_STATUSES, true), 404);
        abort_unless($agency_payout_report->published_at !== null, 404);

        $agency_payout_report->load(['agency.owner', 'items.host.user', 'publishedByAdmin']);
        $data = ['report' => $agency_payout_report];

        if (class_exists(Pdf::class) || app()->bound('dompdf.wrapper')) {
            $pdf = app('dompdf.wrapper');
            $pdf->loadView('pdf.agency-payout-report', $data)
                ->setPaper('a4', 'landscape');

            $filename = 'my-agency-payout-report-' . $agency_payout_report->id . '.pdf';

            return response($pdf->output(), 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'attachment; filename="' . $filename . '"',
                'X-Download-Options' => 'noopen',
                'Cache-Control' => 'private, no-store, no-cache, must-revalidate',
                'Pragma' => 'public',
            ]);
        }

        return response()
            ->view('pdf.agency-payout-report', $data)
            ->header('X-Agency-Payout-Report-Fallback', 'print-view');
    }
}
