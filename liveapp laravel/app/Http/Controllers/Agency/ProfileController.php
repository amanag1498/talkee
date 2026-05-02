<?php

namespace App\Http\Controllers\Agency;

use App\Http\Controllers\Controller;
use App\Models\Agency;
use App\Services\AgencyDashboardService;
use Illuminate\Http\Request;

class ProfileController extends Controller
{
    public function __construct(private AgencyDashboardService $dashboard)
    {
    }

    public function show(Request $request)
    {
        $agency = Agency::where('owner_user_id', $request->user()->id)->firstOrFail();

        return view('agency.profile.show', [
            'agency' => $agency,
            'profile' => $this->dashboard->agencyProfile($agency),
        ]);
    }
}
