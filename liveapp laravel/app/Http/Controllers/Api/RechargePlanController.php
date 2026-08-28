<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\RechargePlanService;
use Illuminate\Http\Request;

class RechargePlanController extends Controller
{
    public function __construct(private RechargePlanService $plans)
    {
    }

    public function index(Request $request)
    {
        return response()->json([
            'ok' => true,
            'data' => $this->plans->activePlans($request->header('X-Client-Platform')),
        ]);
    }
}
