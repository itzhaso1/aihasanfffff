<?php

declare(strict_types=1);

namespace Tests\Support\AI\Models;

use App\AI\Core\Data\ModelRequest;
use App\AI\Core\Data\ModelResponse;
use App\AI\Models\Contracts\ProviderAdapter;
use App\AI\Models\ProviderCapability;
use App\AI\Models\ResolvedModelSettings;

final class FakeProviderAdapter implements ProviderAdapter
{
    public ?ResolvedModelSettings $settings = null;

    public ?ModelRequest $request = null;

    public function __construct(
        private readonly string $provider,
        private readonly ProviderCapability $capabilities = new ProviderCapability,
        private readonly ModelResponse $response = new ModelResponse('ok'),
    ) {}

    public function provider(): string
    {
        return $this->provider;
    }

    public function capabilities(): ProviderCapability
    {
        return $this->capabilities;
    }

    public function generate(ResolvedModelSettings $settings, ModelRequest $request): ModelResponse
    {
        $this->settings = $settings;
        $this->request = $request;

        return $this->response;
    }
}
