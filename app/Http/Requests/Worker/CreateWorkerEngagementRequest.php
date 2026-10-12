<?php

declare(strict_types=1);

namespace App\Http\Requests\Worker;

use App\Enums\WorkerEngagementDurationUnit;
use App\Models\Worker\WorkerProfile;
use App\Support\Authorization\WorkspaceAccess;
use App\Support\Money\Money;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class CreateWorkerEngagementRequest extends FormRequest
{
    public function authorize(): bool
    {
        $workspace = app(WorkspaceContext::class)->workspace();
        $user = $this->user();

        return $workspace !== null
            && $user !== null
            && app(WorkspaceAccess::class)->canManageMembers($user, $workspace);
    }

    public function rules(): array
    {
        return [
            'worker_profile_id' => [
                'required',
                'integer',
                Rule::exists('worker_profiles', 'id'),
            ],
            'job_title' => ['nullable', 'string', 'max:255'],
            'job_description' => ['nullable', 'string', 'max:5000'],
            'starts_at' => ['nullable', 'date', 'required_with:duration_value,duration_unit'],
            'ends_at' => [
                'nullable',
                'date',
                'after_or_equal:starts_at',
            ],
            'duration_value' => [
                'nullable',
                'integer',
                'min:1',
                'required_with:duration_unit',
            ],
            'duration_unit' => [
                'nullable',
                Rule::in(WorkerEngagementDurationUnit::values()),
                'required_with:duration_value',
            ],
            'amount' => ['prohibited'],
            'amount_minor' => ['prohibited'],
            'contract_amount' => ['prohibited'],
            'price' => ['prohibited'],
            'offered_contract_amount_minor' => ['prohibited'],
            'offered_contract_currency' => ['prohibited'],
            'offered_contract_rate_id' => ['prohibited'],
            'agreed_contract_amount_minor' => ['prohibited'],
            'agreed_contract_currency' => ['prohibited'],
            'compensation_terms_json' => ['nullable', 'array'],
            'products' => ['nullable', 'array', 'max:100'],
            'products.*' => ['required', 'array'],
            'products.*.id' => [
                'required',
                'integer',
                'distinct',
                Rule::exists('chat_catalog_items', 'id')
                    ->where(fn ($query) => $query->where(
                        'workspace_id',
                        app(WorkspaceContext::class)->workspaceId(),
                    )),
            ],
            'products.*.minimum_selling_price' => ['nullable', 'decimal:0,2', 'min:0'],
            'products.*.maximum_selling_price' => ['nullable', 'decimal:0,2', 'min:0'],
            ...WorkerEngagementGuideRules::rules(),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            ...WorkerEngagementGuideRules::messages(),
            'starts_at.required_with' => 'تاريخ البداية مطلوب عند تحديد مدة التعاقد.',
            'duration_value.required_with' => 'أدخل قيمة مدة التعاقد.',
            'duration_value.integer' => 'يجب أن تكون مدة التعاقد رقماً صحيحاً.',
            'duration_value.min' => 'يجب أن تكون مدة التعاقد أكبر من صفر.',
            'duration_unit.required_with' => 'اختر وحدة مدة التعاقد.',
            'duration_unit.in' => 'وحدة مدة التعاقد غير مدعومة.',
            'amount.prohibited' => 'لا يمكن تحديد سعر التعاقد يدويًا. المنصة هي مصدر السعر.',
            'amount_minor.prohibited' => 'لا يمكن تحديد سعر التعاقد يدويًا. المنصة هي مصدر السعر.',
            'contract_amount.prohibited' => 'لا يمكن تحديد سعر التعاقد يدويًا. المنصة هي مصدر السعر.',
            'price.prohibited' => 'لا يمكن تحديد سعر التعاقد يدويًا. المنصة هي مصدر السعر.',
            'offered_contract_amount_minor.prohibited' => 'لا يمكن تحديد سعر التعاقد يدويًا. المنصة هي مصدر السعر.',
            'offered_contract_currency.prohibited' => 'لا يمكن تحديد سعر التعاقد يدويًا. المنصة هي مصدر السعر.',
            'offered_contract_rate_id.prohibited' => 'لا يمكن تحديد سعر التعاقد يدويًا. المنصة هي مصدر السعر.',
            'agreed_contract_amount_minor.prohibited' => 'لا يمكن تحديد سعر التعاقد يدويًا. المنصة هي مصدر السعر.',
            'agreed_contract_currency.prohibited' => 'لا يمكن تحديد سعر التعاقد يدويًا. المنصة هي مصدر السعر.',
        ];
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $products = $this->input('products', []);
                if (! is_array($products)) {
                    return;
                }

                foreach ($products as $index => $product) {
                    if (! is_array($product)) {
                        continue;
                    }

                    $minimum = $product['minimum_selling_price'] ?? null;
                    $maximum = $product['maximum_selling_price'] ?? null;

                    if (
                        $minimum === null
                        || $minimum === ''
                        || $maximum === null
                        || $maximum === ''
                        || $validator->errors()->has("products.{$index}.minimum_selling_price")
                        || $validator->errors()->has("products.{$index}.maximum_selling_price")
                    ) {
                        continue;
                    }

                    if (Money::cmp($minimum, $maximum) > 0) {
                        $validator->errors()->add(
                            "products.{$index}.maximum_selling_price",
                            'يجب ألا يكون الحد الأدنى أكبر من الحد الأعلى.',
                        );
                    }
                }
            },
            function (Validator $validator): void {
                $hasValue = $this->filled('duration_value');
                $hasUnit = $this->filled('duration_unit');

                if (($hasValue || $hasUnit) && $this->filled('ends_at')) {
                    $validator->errors()->add(
                        'ends_at',
                        'لا ترسل تاريخ النهاية مع مدة التعاقد؛ يحسبها النظام تلقائياً.',
                    );
                }

                if ($hasValue === $hasUnit) {
                    return;
                }

                $validator->errors()->add(
                    $hasValue ? 'duration_unit' : 'duration_value',
                    'يجب إرسال قيمة مدة التعاقد ووحدتها معاً.',
                );
            },
        ];
    }

    protected function prepareForValidation(): void
    {
        $merge = [];
        $workerProfile = $this->route('workerProfile');

        if ($workerProfile instanceof WorkerProfile) {
            $merge['worker_profile_id'] = $workerProfile->id;
        }

        $guide = WorkerEngagementGuideRules::prepare($this->input('guide'));
        if ($guide !== null) {
            $merge['guide'] = $guide;
        }

        if ($merge !== []) {
            $this->merge($merge);
        }
    }
}
