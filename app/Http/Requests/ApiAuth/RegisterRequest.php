<?php

namespace App\Http\Requests\ApiAuth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'phone' => ['nullable', 'string', 'max:32', 'unique:users,phone'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            // Optional legacy field — registration does not require legal/activity type.
            'workspace_type' => ['nullable', Rule::in(config('workspace.types'))],
            'workspace_name' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array{name:string,email:string,password:string,phone?:string|null,workspace_type:string,workspace_name?:string|null}
     */
    public function registrationPayload(): array
    {
        $validated = $this->validated();

        return [
            'name' => (string) $validated['name'],
            'email' => (string) $validated['email'],
            'password' => (string) $validated['password'],
            'phone' => $validated['phone'] ?? null,
            'workspace_type' => $validated['workspace_type'] ?? 'company',
            'workspace_name' => $validated['workspace_name'] ?? null,
        ];
    }
}
