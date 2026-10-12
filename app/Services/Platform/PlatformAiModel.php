<?php

namespace App\Services\Platform;

/**
 * The model pair currently in force for every AI call on the platform.
 */
final readonly class PlatformAiModel
{
    public function __construct(
        public string $provider,
        public string $model,
        public ?string $fallbackProvider,
        public ?string $fallbackModel,
        public bool $usingFallback,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'provider' => $this->provider,
            'model' => $this->model,
            'fallback_provider' => $this->fallbackProvider,
            'fallback_model' => $this->fallbackModel,
            'using_fallback' => $this->usingFallback,
            'source' => 'platform',
        ];
    }
}
