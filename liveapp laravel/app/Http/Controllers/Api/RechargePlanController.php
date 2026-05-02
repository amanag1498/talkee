<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\RechargePlanService;

class RechargePlanController extends Controller
{
    public function __construct(private RechargePlanService $plans)
    {
    }

    public function index()
    {
        return response()->json([
            'ok' => true,
            'data' => $this->plans->activePlans(),
        ]);
    }
}
