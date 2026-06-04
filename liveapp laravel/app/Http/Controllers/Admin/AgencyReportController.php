<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Agency;
use App\Models\AgencyPayoutReport;
use App\Services\AgencyReportService;
use Illuminate\Http\Request;

class AgencyReportController extends Controller
{
    public function __construct(private AgencyReportService $reports)
    {
    }

    public function index(Request $request)
    {
        $report = $this->reports->overview($request);
        $from = $report['from']->copy()->startOfDay();
        $to = $report['to']->copy()->endOfDay();
        $agencyIds = collect($report['weekly_rows'])->pluck('agency.id')->filter()->all();
        $payoutReports = AgencyPayoutReport::query()
            ->with('publishedByAdmin')
            ->whereIn('agency_id', $agencyIds)
            ->where('period_start', $from)
            ->where('period_end', $to)
            ->get()
            ->keyBy('agency_id');

        $report['weekly_rows'] = collect($report['weekly_rows'])->map(function (array $row) use ($payoutReports) {
            $row['payout_report'] = $payoutReports->get($row['agency']->id);
            return $row;
        })->all();

        return view('admin.reports.agencies.index', [
            'report' => $report,
        ]);
    }

    public function show(Agency $agency, Request $request)
    {
        $report = $this->reports->detail($agency, $request);
        $from = $report['from']->copy()->startOfDay();
        $to = $report['to']->copy()->endOfDay();
        $report['payout_report'] = AgencyPayoutReport::query()
            ->with('publishedByAdmin')
            ->where('agency_id', $agency->id)
            ->where('period_start', $from)
            ->where('period_end', $to)
            ->latest('id')
            ->first();

        return view('admin.reports.agencies.show', [
            'report' => $report,
        ]);
    }
}
