<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class UpdateUserSubscriptionRequest extends FormRequest
{
    public function authorize(): bool {
        return true;
    }

    public function rules(): array {
        return [
            'plan_id'      => 'required|exists:subscription_plans,id',
            'status'       => 'required|in:active,cancelled,expired',
            'starts_at'    => 'nullable|date',
            'ends_at'      => 'nullable|date|after_or_equal:starts_at',
            'charge_coins' => 'sometimes|boolean',   // if admin wants to re-charge during edit
        ];
    }
}
