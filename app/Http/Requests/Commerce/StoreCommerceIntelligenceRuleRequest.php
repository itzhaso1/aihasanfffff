<?php

namespace App\Http\Requests\Commerce;

use Illuminate\Foundation\Http\FormRequest;

class StoreCommerceIntelligenceRuleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:128'],
            'rule_type' => ['required', 'string', 'in:required_attribute,incompatibility,recommendation_constraint,quantity_constraint,prerequisite,alternative'],
            'target_entity_type' => ['nullable', 'string', 'max:64'],
            'target_entity_id' => ['nullable', 'integer'],
            'category_id' => ['nullable', 'integer'],
            'conditions' => ['required', 'array'],
            'actions' => ['required', 'array'],
            'priority' => ['nullable', 'integer'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
