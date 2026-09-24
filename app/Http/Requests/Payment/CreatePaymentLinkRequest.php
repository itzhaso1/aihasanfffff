<?php

namespace App\Http\Requests\Payment;

use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreatePaymentLinkRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $workspaceId = app(WorkspaceContext::class)->workspaceId();

        return [
            'order_id' => [
                'required',
                'integer',
                Rule::exists('orders', 'id')->where(
                    fn ($query) => $query->where('workspace_id', $workspaceId)
                ),
            ],
            'payment_gateway_id' => [
                'nullable',
                'integer',
                Rule::exists('payment_gateways', 'id')->where(
                    fn ($query) => $query->where('workspace_id', $workspaceId)
                ),
            ],
        ];
    }
}
