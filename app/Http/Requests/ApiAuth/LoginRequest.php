<?php

namespace App\Http\Requests\ApiAuth;

use Illuminate\Foundation\Http\FormRequest;

class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email_or_phone' => ['required', 'string'],
            'password' => ['required', 'string'],
            'workspace_id' => ['required', 'integer', 'exists:workspaces,id'],
        ];
    }
}
