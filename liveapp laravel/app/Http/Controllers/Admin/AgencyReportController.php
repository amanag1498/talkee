<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Agency;
use App\Services\AgencyReportService;
use Illuminate\Http\Request;

class AgencyReportController extends Controller
{
    public function __construct(private AgencyReportService $reports)
    {
    }

    public function index(Request $request)
    {
        return view('admin.reports.agencies.index', [
            'report' => $this->reports->overview($request),
        ]);
    }

    public function show(Agency $agency, Request $request)
    {
        return view('admin.reports.agencies.show', [
            'report' => $this->reports->detail($agency, $request),
        ]);
    }
}
