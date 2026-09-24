<?php

namespace App\Http\Requests\Chat;

use App\Support\Chat\ChatCatalogAccess;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreChatCatalogDigitalUnitImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        $workspace = app(WorkspaceContext::class)->workspace();

        return $workspace !== null
            && $this->user() !== null
            && app(ChatCatalogAccess::class)->canManageDigitalInventory($this->user(), $workspace);
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('secrets_text') && ! $this->has('secrets')) {
            $lines = array_values(array_filter(
                array_map('trim', explode("\n", (string) $this->input('secrets_text'))),
                fn ($line) => $line !== ''
            ));
            $this->merge(['secrets' => $lines]);
        }
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'unit_type' => ['required', 'in:code,license,credential,subscription,file,custom'],
            'secrets' => ['required_without:file_asset', 'array', 'min:1', 'max:1000'],
            'secrets.*' => ['string', 'min:1', 'max:10000'],
            'expires_at' => ['nullable', 'date'],
            // File metadata is private server-side storage metadata. It is
            // accepted only for a file unit and never returned by resources.
            'file_asset' => ['nullable', 'array', 'required_if:unit_type,file', 'max:10'],
            'file_asset.disk' => ['nullable', 'in:local,backups'],
            'file_asset.path' => ['required_if:unit_type,file', 'string', 'max:1000'],
            'file_asset.filename' => ['nullable', 'string', 'max:255'],
            'file_asset.mime_type' => ['nullable', 'string', 'max:255'],
            'file_asset.byte_size' => ['nullable', 'integer', 'min:0'],
            'file_asset.checksum' => ['nullable', 'string', 'max:255'],
            'file_asset.version' => ['nullable', 'string', 'max:64'],
        ];
    }
}
