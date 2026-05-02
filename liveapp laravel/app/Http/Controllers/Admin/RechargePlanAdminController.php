<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\RechargePlan;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class RechargePlanAdminController extends Controller
{
    public function index()
    {
        $plans = RechargePlan::query()
            ->orderBy('sort_order')
            ->orderBy('amount_rupees')
            ->get();

        return view('admin.recharge-plans.index', compact('plans'));
    }

    public function create()
    {
        return view('admin.recharge-plans.create', [
            'plan' => new RechargePlan([
                'is_active' => true,
                'sort_order' => ((int) RechargePlan::query()->max('sort_order')) + 1,
            ]),
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        RechargePlan::query()->create($this->payload($data));

        return redirect()
            ->route('admin.recharge-plans.index')
            ->with('ok', 'Recharge plan created.');
    }

    public function edit(RechargePlan $recharge_plan)
    {
        $plan = $recharge_plan;

        return view('admin.recharge-plans.edit', compact('plan'));
    }

    public function update(Request $request, RechargePlan $recharge_plan)
    {
        $data = $this->validated($request, $recharge_plan);
        $recharge_plan->update($this->payload($data));

        return redirect()
            ->route('admin.recharge-plans.index')
            ->with('ok', 'Recharge plan updated.');
    }

    public function destroy(RechargePlan $recharge_plan)
    {
        $recharge_plan->delete();

        return redirect()
            ->route('admin.recharge-plans.index')
            ->with('ok', 'Recharge plan deleted.');
    }

    private function validated(Request $request, ?RechargePlan $plan = null): array
    {
        return $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'amount_rupees' => [
                'required',
                'numeric',
                'min:1',
                Rule::unique('recharge_plans', 'amount_rupees')->ignore($plan?->id),
            ],
            'coins' => ['required', 'integer', 'min:1'],
            'bonus_coins' => ['nullable', 'integer', 'min:0'],
            'sort_order' => ['required', 'integer', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
        ]);
    }

    private function payload(array $data): array
    {
        $coins = (int) $data['coins'];
        $bonusCoins = (int) ($data['bonus_coins'] ?? 0);

        return [
            'title' => trim($data['title']),
            'amount_rupees' => (float) $data['amount_rupees'],
            'coins' => $coins,
            'bonus_coins' => $bonusCoins,
            'total_coins' => $coins + $bonusCoins,
            'sort_order' => (int) $data['sort_order'],
            'is_active' => (bool) ($data['is_active'] ?? false),
        ];
    }
}
