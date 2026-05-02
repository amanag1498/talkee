<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AgencyApplicationService;
use App\Services\ApplicationSummaryService;
use App\Services\HostApplicationService;
use App\Services\HostEnrollmentService;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

class ApplicationApiController extends Controller
{
    public function __construct(
        private ApplicationSummaryService $summary,
        private AgencyApplicationService $agencyApplications,
        private HostApplicationService $hostApplications,
        private HostEnrollmentService $hostEnrollment,
    ) {
    }

    public function index(Request $request)
    {
        return response()->json([
            'ok' => true,
            'data' => $this->summary->summaryFor($request->user()),
        ]);
    }

    public function applyAgency(Request $request)
    {
        $data = $request->validate([
            'agency_name' => 'required|string|max:120',
            'legal_name' => 'nullable|string|max:120',
            'contact_phone' => 'nullable|string|max:30',
            'website' => 'nullable|url',
            'about' => 'nullable|string|max:1000',
        ]);

        $application = $this->agencyApplications->submit($request->user(), $data);

        return response()->json([
            'ok' => true,
            'application_id' => $application->id,
            'message' => 'Agency application submitted.',
        ], 201);
    }

    public function applyHost(Request $request)
    {
        $data = $request->validate([
            'stage_name' => 'nullable|string|max:120',
            'contact_phone' => 'nullable|string|max:30',
            'country' => 'nullable|string|max:80',
            'city' => 'nullable|string|max:80',
            'about' => 'nullable|string|max:1000',
        ]);

        $application = $this->hostApplications->submit($request->user(), $data);

        return response()->json([
            'ok' => true,
            'application_id' => $application->id,
            'message' => 'Host application submitted.',
        ], 201);
    }

    public function enrollHost(Request $request)
    {
        $data = $request->validate([
            'agency_id' => 'required|exists:agencies,id',
            'message' => 'nullable|string|max:1000',
        ]);

        $application = $this->hostEnrollment->submit($request->user(), $data);

        return response()->json([
            'ok' => true,
            'application_id' => $application->id,
            'message' => 'Enroll request submitted.',
        ], 201);
    }
}
