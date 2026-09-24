<?php

namespace App\Http\Requests\Chat;

use App\Support\Chat\ChatCatalogAccess;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateChatCatalogCommunicationProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        $workspace = app(WorkspaceContext::class)->workspace();

        return $workspace !== null
            && $this->user() !== null
            && app(ChatCatalogAccess::class)->canManageSettings($this->user(), $workspace);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'primary_language' => ['sometimes', 'required', 'in:ar,en,bilingual'],
            'supported_languages' => ['nullable', 'array', 'min:1', 'max:10'],
            'supported_languages.*' => ['in:ar,en'],
            'arabic_dialect' => ['nullable', 'in:msa,jordanian,saudi,gulf,egyptian,levantine,iraqi,moroccan,algerian,tunisian'],
            'formality' => ['nullable', 'in:very_formal,formal,neutral,friendly,casual'],
            'communication_style' => ['nullable', 'in:concise,balanced,detailed'],
            'greeting_behavior' => ['nullable', 'in:disabled,first_contact,always,channel_based,custom_greeting'],
            'name_usage' => ['nullable', 'in:none,first_name,display_name,full_name_where_appropriate'],
            'language_fallback' => ['nullable', 'in:ar,en'],
            'date_format' => ['nullable', 'in:Y-m-d,d/m/Y,m/d/Y'],
            'time_format' => ['nullable', 'in:H:i,h:i A'],
            'number_format' => ['nullable', 'in:western,arabic_indic'],
            'currency_format' => ['nullable', 'in:symbol_after,symbol_before,code_after'],
            'units' => ['nullable', 'in:metric,imperial,automatic'],
            'response_length' => ['nullable', 'in:concise,balanced,detailed'],
            'punctuation_style' => ['nullable', 'in:standard,minimal'],
            'emoji_policy' => ['nullable', 'in:disabled,limited,normal'],
        ];
    }
}
