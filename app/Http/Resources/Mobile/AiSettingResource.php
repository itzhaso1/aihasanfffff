<?php

namespace App\Http\Resources\Mobile;

use App\Models\AiSetting;
use App\Services\AI\AssistantName;
use App\Services\Platform\PlatformAiConfiguration;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin AiSetting */
class AiSettingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $platform = app(PlatformAiConfiguration::class)->snapshot();

        return [
            'id' => $this->id,
            'name' => AssistantName::sanitize($this->name) ?? AssistantName::DEFAULT,
            'assistant_name' => AssistantName::sanitize($this->name) ?? AssistantName::DEFAULT,
            'ai_role' => $this->ai_role,
            'instructions' => $this->instructions,
            'tone' => $this->tone,
            'reply_style' => $this->reply_style,
            'rules' => $this->rules ?? [],
            'business_information' => $this->business_information ?? [],
            'agent_persona' => $this->agent_persona ?? [],
            'provider' => $platform->provider,
            'model' => $platform->model,
            'max_tokens' => (int) $this->max_tokens,
            'temperature' => $this->temperature !== null ? (float) $this->temperature : null,
            'is_active' => (bool) $this->is_active,
        ];
    }
}
