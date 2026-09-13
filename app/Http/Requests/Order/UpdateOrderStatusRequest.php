<?php

namespace App\Http\Requests\Order;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateOrderStatusRequest extends FormRequest
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
        return [
            'status' => ['nullable', 'in:draft,confirmed,cancelled,completed'],
            'pos_status' => ['nullable', 'in:new,accepted,preparing,ready,delivered,completed,cancelled'],
            'payment_status' => ['nullable', 'in:pending,paid,failed,refunded'],
            'fulfillment_status' => ['nullable', 'in:unfulfilled,processing,fulfilled,cancelled'],
            'shipping_status' => ['nullable', 'in:not_shipped,processing,shipped,delivered,returned'],
        ];
    }
}
