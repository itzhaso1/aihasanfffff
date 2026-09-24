<?php

namespace App\Models;

use App\Enums\ChatCatalog\ChatCatalogArabicDialect;
use App\Enums\ChatCatalog\ChatCatalogFormality;
use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'chat_catalog_id',
    'workspace_id',
    'primary_language',
    'supported_languages',
    'arabic_dialect',
    'formality',
    'communication_style',
    'greeting_behavior',
    'name_usage',
    'language_fallback',
    'date_format',
    'time_format',
    'number_format',
    'currency_format',
    'units',
    'response_length',
    'punctuation_style',
    'emoji_policy',
])]
class ChatCatalogCommunicationProfile extends WorkspaceScopedModel
{
    use BelongsToWorkspace;

    protected function casts(): array
    {
        return [
            'supported_languages' => 'array',
            'arabic_dialect' => ChatCatalogArabicDialect::class,
            'formality' => ChatCatalogFormality::class,
        ];
    }

    public function catalog(): BelongsTo
    {
        return $this->belongsTo(ChatCatalog::class, 'chat_catalog_id');
    }

    /**
     * Customer-facing merchandising data for AI context (untrusted business data).
     *
     * @return array<string, mixed>
     */
    public function toUntrustedAiPayload(): array
    {
        return [
            '_trust' => 'untrusted_business_data',
            'primary_language' => $this->primary_language,
            'supported_languages' => $this->supported_languages ?? [],
            'arabic_dialect' => $this->arabic_dialect?->value,
            'formality' => $this->formality?->value,
            'communication_style' => $this->communication_style,
            'greeting_behavior' => $this->greeting_behavior,
            'name_usage' => $this->name_usage,
            'language_fallback' => $this->language_fallback,
            'date_format' => $this->date_format,
            'time_format' => $this->time_format,
            'number_format' => $this->number_format,
            'currency_format' => $this->currency_format,
            'units' => $this->units,
            'response_length' => $this->response_length,
            'punctuation_style' => $this->punctuation_style,
            'emoji_policy' => $this->emoji_policy,
        ];
    }
}
