<?php

declare(strict_types=1);

namespace App\AI\Models\Adapters;

use App\AI\Core\Data\ModelRequest;
use App\AI\Core\Data\ModelResponse;
use App\AI\Models\Contracts\ProviderAdapter;
use App\AI\Models\Mapping\OpenAiPayloadMapper;
use App\AI\Models\Mapping\OpenAiResponseNormalizer;
use App\AI\Models\ModelGatewayException;
use App\AI\Models\ProviderCapability;
use App\AI\Models\ProviderId;
use App\AI\Models\ResolvedModelSettings;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

final class OpenAiAdapter implements ProviderAdapter
{
    private const ENDPOINT = 'https://api.openai.com/v1/chat/completions';

    public function __construct(
        private readonly OpenAiPayloadMapper $payloads = new OpenAiPayloadMapper,
        private readonly OpenAiResponseNormalizer $responses = new OpenAiResponseNormalizer,
    ) {}

    public function provider(): string
    {
        return ProviderId::OPENAI;
    }

    public function capabilities(): ProviderCapability
    {
        return ProviderCapability::openai();
    }

    public function generate(ResolvedModelSettings $settings, ModelRequest $request): ModelResponse
    {
        $apiKey = trim((string) config('services.openai.key'));
        if ($apiKey === '') {
            throw ModelGatewayException::missingApiKey($this->provider());
        }

        try {
            $response = Http::timeout(30)
                ->withToken($apiKey)
                ->post(self::ENDPOINT, $this->payloads->payload($settings, $request))
                ->throw()
                ->json();
        } catch (RequestException $exception) {
            throw ModelGatewayException::httpError($this->provider(), (int) ($exception->response?->status() ?? 0));
        } catch (ConnectionException) {
            throw ModelGatewayException::timeout($this->provider());
        }

        if (! is_array($response)) {
            throw ModelGatewayException::emptyResponse();
        }

        return $this->responses->normalize($response, $settings->model);
    }
}
