<?php
namespace App\Http\Controllers\Me;

use App\Http\Controllers\Controller;
use App\Services\ApplicationSummaryService;
use Illuminate\Http\Request;

class ApplicationsController extends Controller
{
      public function __construct(private ApplicationSummaryService $summary)
      {
      }

      public function index(Request $request)
    {
        $data = $this->summary->summaryFor($request->user());
        $agencyRequests = collect($data['applications'])->where('type', 'agency')->values();
        $hostRequests = collect($data['applications'])->where('type', 'host')->values();
        $enrollRequests = collect($data['applications'])->where('type', 'host_enroll')->values();

        return view('me.applications', compact('agencyRequests','hostRequests','enrollRequests'));
    }
}
