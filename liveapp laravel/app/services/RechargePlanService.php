<?php

namespace App\Services;

use App\Models\RechargePlan;

class RechargePlanService
{
    public function activePlans(): array
    {
        return RechargePlan::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(fn (RechargePlan $plan) => [
                'id' => $plan->id,
                'title' => $plan->title,
                'amount_rupees' => (float) $plan->amount_rupees,
                'coins' => (int) $plan->coins,
                'bonus_coins' => (int) $plan->bonus_coins,
                'total_coins' => (int) $plan->total_coins,
                'sort_order' => (int) $plan->sort_order,
            ])
            ->values()
            ->all();
    }
}
