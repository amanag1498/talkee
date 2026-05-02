<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use App\Models\SubscriptionPlan;

class UpdateSubscriptionPlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Routes are already protected by role:admin; allow here.
        return true;
    }

    public function rules(): array
    {
        // Resolve the bound model or ID from the resource route.
        // Resource routes bind as 'subscription_plan' by default.
        /** @var SubscriptionPlan|int|string|null $planParam */
        $planParam = $this->route('subscription_plan') ?? $this->route('plan') ?? null;
        $planId = $planParam instanceof SubscriptionPlan ? $planParam->id : $planParam;

        return [
            'name' => [
                'required',
                'string',
                'max:100',
                Rule::unique('subscription_plans', 'name')->ignore($planId),
            ],
            'price_coins'   => ['required','integer','min:1'],
            'duration_days' => ['required','integer','min:1'],
            'perks'         => ['nullable','json'],  // allow empty/null
            'is_active'     => ['sometimes','boolean'],
        ];
    }
}
