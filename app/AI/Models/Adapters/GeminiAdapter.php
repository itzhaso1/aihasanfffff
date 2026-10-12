<?php

declare(strict_types=1);

namespace App\AI\Models\Adapters;

use App\AI\Core\Data\ModelRequest;
use App\AI\Core\Data\ModelResponse;
use App\AI\Models\Contracts\ProviderAdapter;
use App\AI\Models\Mapping\GeminiPayloadMapper;
use App\AI\Models\Mapping\GeminiResponseNormalizer;
use App\AI\Models\ModelGatewayException;
use App\AI\Models\ProviderCapability;
use App\AI\Models\ProviderId;
use App\AI\Models\ResolvedModelSettings;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

final class GeminiAdapter implements ProviderAdapter
{
    public function __construct(
        private readonly GeminiPayloadMapper $payloads = new GeminiPayloadMapper,
        private readonly GeminiResponseNormalizer $responses = new GeminiResponseNormalizer,
    ) {}

    public function provider(): string
    {
        return ProviderId::GEMINI;
    }

    public function capabilities(): ProviderCapability
    {
        return ProviderCapability::gemini();
    }

    public function generate(ResolvedModelSettings $settings, ModelRequest $request): ModelResponse
    {
        $apiKey = trim((string) config('services.google_ai_studio.key'));
        if ($apiKey === '') {
            throw ModelGatewayException::missingApiKey($this->provider());
        }

        $endpoint = rtrim((string) config('ai.google_ai_studio.base_url'), '/').'/models/'.$settings->model.':generateContent';

        try {
            $response = Http::timeout(30)
                ->retry(2, 300)
                ->withHeaders(['x-goog-api-key' => $apiKey])
                ->post($endpoint, $this->payloads->payload($settings, $request))
                ->throw()
                ->json();
        } catch (RequestException $exception) {
            $status = (int) ($exception->response?->status() ?? 0);
            throw $status === 429
                ? ModelGatewayException::rateLimited($this->provider())
                : ModelGatewayException::httpError($this->provider(), $status);
        } catch (ConnectionException) {
            throw ModelGatewayException::timeout($this->provider());
        }

        if (! is_array($response)) {
            throw ModelGatewayException::emptyResponse();
        }

        return $this->responses->normalize($response, $settings->model);
    }
}
