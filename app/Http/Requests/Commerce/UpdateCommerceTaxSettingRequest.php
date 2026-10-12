<?php

namespace App\Http\Requests\Commerce;

use App\Models\Workspace;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateCommerceTaxSettingRequest extends FormRequest
{
    public function authorize(): bool
    {
        $workspace = app(WorkspaceContext::class)->workspace();

        return $workspace instanceof Workspace
            && $this->user() !== null
            && $this->user()->can('update', $workspace);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'default_tax_rate' => ['required', 'numeric', 'min:0', 'max:100'],
        ];
    }
}
