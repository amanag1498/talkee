<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreSubscriptionPlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Routes are already protected by role:admin; allow here.
        return true;
    }

    public function rules(): array
    {
        return [
            'name'          => 'required|string|max:100|unique:subscription_plans,name',
            'price_coins'   => 'required|integer|min:1',
            'duration_days' => 'required|integer|min:1',
            'perks'         => 'nullable|json',   // allow empty/null; if present must be valid JSON
            'is_active'     => 'sometimes|boolean',
        ];
    }
}
