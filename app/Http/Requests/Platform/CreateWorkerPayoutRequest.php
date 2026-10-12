<?php

declare(strict_types=1);

namespace App\Http\Requests\Platform;

use Illuminate\Foundation\Http\FormRequest;

class CreateWorkerPayoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('platform_admin') !== null;
    }

    public function rules(): array
    {
        return [
            'period_id' => ['required', 'integer', 'min:1'],
        ];
    }
}
