<?php

namespace App\Http\Requests\Commerce;

use Illuminate\Foundation\Http\FormRequest;

class EvaluateCommerceRecommendationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'item_id' => ['required', 'integer'],
            'variant_id' => ['nullable', 'integer'],
            'customer_context' => ['nullable', 'array'],
        ];
    }
}
