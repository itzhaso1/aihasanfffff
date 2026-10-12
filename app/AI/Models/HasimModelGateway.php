<?php

declare(strict_types=1);

namespace App\AI\Models;

use App\AI\Core\Contracts\ModelGateway;
use App\AI\Core\Data\ModelRequest;
use App\AI\Core\Data\ModelResponse;
use App\AI\Models\Adapters\GeminiAdapter;
use App\AI\Models\Adapters\OpenAiAdapter;
use App\AI\Models\Contracts\ProviderAdapter;

/**
 * Production ModelGateway for the Hasim Runtime. Inbox and other sandboxed
 * runtimes resolve employee-scoped settings through this gateway.
 */
final class HasimModelGateway implements ModelGateway
{
    /** @var array<string, ProviderAdapter> */
    private array $adapters;

    /**
     * @param  iterable<ProviderAdapter>  $adapters
     */
    public function __construct(
        private readonly ModelSettingsResolver $settings,
        iterable $adapters = [],
    ) {
        $this->adapters = [];
        $items = $adapters === [] ? [new GeminiAdapter, new OpenAiAdapter] : $adapters;
        foreach ($items as $adapter) {
            if (! $adapter instanceof ProviderAdapter) {
                throw ModelGatewayException::unsupportedProvider('invalid');
            }
            $this->adapters[$adapter->provider()] = $adapter;
        }
    }

    public function generate(ModelRequest $request): ModelResponse
    {
        $settings = $this->settings->resolve($request->employeeId);
        $adapter = $this->adapters[$settings->provider] ?? throw ModelGatewayException::unsupportedProvider($settings->provider);

        return $adapter->generate($settings, $this->requestFor($adapter, $request));
    }

    private function requestFor(ProviderAdapter $adapter, ModelRequest $request): ModelRequest
    {
        if ($adapter->capabilities()->nativeTools || ($request->tools === [] && $request->toolExchanges === [])) {
            return $request;
        }

        return new ModelRequest($request->employeeId, $request->runMode, $request->prompt);
    }
}
