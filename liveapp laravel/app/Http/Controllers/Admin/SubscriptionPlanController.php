<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreSubscriptionPlanRequest;
use App\Http\Requests\UpdateSubscriptionPlanRequest;
use App\Models\SubscriptionPlan;
use Illuminate\Http\Request;

class SubscriptionPlanController extends Controller
{
    public function index()
    {
        $plans = SubscriptionPlan::orderBy('price_coins')->paginate(20);
        return view('admin.subscriptions.plans.index', compact('plans'));
    }

    public function create()
    {
        return view('admin.subscriptions.plans.create');
    }

    public function store(StoreSubscriptionPlanRequest $request)
    {
        $data = $request->validated();

        // Perks: convert empty string to null; decode JSON string to array if provided
        if (array_key_exists('perks', $data)) {
            $data['perks'] = strlen(trim((string)$data['perks'])) ? json_decode($data['perks'], true) : null;
        }

        // Checkbox normalization
        $data['is_active'] = $request->boolean('is_active');

        SubscriptionPlan::create($data);

        return redirect()
            ->route('admin.subscription-plans.index')
            ->with('success', 'Plan created.');
    }

    public function edit(SubscriptionPlan $subscription_plan)
    {
        // View expects $plan
        $plan = $subscription_plan;
        return view('admin.subscriptions.plans.edit', compact('plan'));
    }

    public function update(UpdateSubscriptionPlanRequest $request, SubscriptionPlan $subscription_plan)
    {
        $data = $request->validated();

        // Perks: convert empty string to null; decode JSON string to array if provided
        if (array_key_exists('perks', $data)) {
            $data['perks'] = strlen(trim((string)$data['perks'])) ? json_decode($data['perks'], true) : null;
        }

        // Checkbox normalization
        $data['is_active'] = $request->boolean('is_active');

        $subscription_plan->update($data);

        return redirect()
            ->route('admin.subscription-plans.index')
            ->with('success', 'Plan updated.');
    }

    public function destroy(SubscriptionPlan $subscription_plan)
    {
        $subscription_plan->delete();

        return back()->with('success', 'Plan deleted.');
    }
}
