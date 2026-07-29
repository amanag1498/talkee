<?php

namespace App\Services;

use App\Models\RechargePlan;

class RechargePlanService
{
    public function activePlans(): array
    {
        return $this->activePlanQuery()
            ->get()
            ->map(fn (RechargePlan $plan) => $this->publicPlan($plan))
            ->values()
            ->all();
    }

    public function activeAgencyPlans(): array
    {
        return $this->activePlanQuery()
            ->get()
            ->map(fn (RechargePlan $plan) => array_merge($this->publicPlan($plan), [
                'agency_bonus_coins' => (int) $plan->agency_bonus_coins,
                'agency_total_coins' => (int) $plan->total_coins + (int) $plan->agency_bonus_coins,
            ]))
            ->values()
            ->all();
    }

    private function activePlanQuery()
    {
        return RechargePlan::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    private function publicPlan(RechargePlan $plan): array
    {
        return [
            'id' => $plan->id,
            'title' => $plan->title,
            'amount_rupees' => (float) $plan->amount_rupees,
            'coins' => (int) $plan->coins,
            'bonus_coins' => (int) $plan->bonus_coins,
            'total_coins' => (int) $plan->total_coins,
            'sort_order' => (int) $plan->sort_order,
        ];
    }
}
