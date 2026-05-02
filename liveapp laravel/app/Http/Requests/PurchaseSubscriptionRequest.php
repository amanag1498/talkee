<?php

use Illuminate\Foundation\Http\FormRequest;

class PurchaseSubscriptionRequest extends FormRequest
{
    public function authorize(): bool { return $this->user() !== null; }
    public function rules(): array {
        return [
            'plan_id' => 'required|exists:subscription_plans,id',
        ];
    }
}
