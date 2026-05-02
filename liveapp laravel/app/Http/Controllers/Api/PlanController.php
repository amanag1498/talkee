<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SubscriptionPlan;

class PlanController extends Controller
{
    public function index()
    {
        return SubscriptionPlan::where('is_active',true)
            ->select('id','name','price_coins','duration_days','perks','is_active')
            ->orderBy('price_coins')
            ->get();
    }
}
