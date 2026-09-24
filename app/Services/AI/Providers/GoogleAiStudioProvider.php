<?php

namespace App\Services\AI\Providers;

use App\Services\AI\Contracts\AiProviderInterface;
use App\Services\AI\Inbox\Tools\InboxAgentToolDefinition;
use App\Services\AI\Providers\GoogleAiStudio\GoogleAiStudioContentsMapper;
use App\Services\AI\Providers\GoogleAiStudio\GoogleAiStudioToolResponseMapper;
use App\Services\AI\Providers\GoogleAiStudio\GoogleAiStudioToolSchemaMapper;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class GoogleAiStudioProvider implements AiProviderInterface
{
    public function __construct(
        private readonly GoogleAiStudioContentsMapper $contentsMapper,
        private readonly GoogleAiStudioToolSchemaMapper $toolSchemaMapper,
        private readonly GoogleAiStudioToolResponseMapper $toolResponseMapper,
    ) {}

    public function supportsNativeToolCalling(): bool
    {
        return true;
    }

    /**
     * @param  list<InboxAgentToolDefinition>  $toolDefinitions
     */
    public function generate(
        array $messages,
        string $model,
        float $temperature,
        int $maxTokens,
        array $toolDefinitions = [],
    ): array {
        $apiKey = config('services.google_ai_studio.key');
        if (! $apiKey) {
            throw new RuntimeException('Google AI Studio API key is not configured.');
        }

        $messageCollection = collect($messages);
        $systemInstruction = (string) ($messageCollection->firstWhere('role', 'system')['content'] ?? '');
        $contents = $this->contentsMapper->map($messages);

        $endpoint = rtrim((string) config('ai.google_ai_studio.base_url'), '/').'/models/'.$model.':generateContent';

        $payload = [
            'contents' => $contents,
            'generationConfig' => [
                'temperature' => $temperature,
                'maxOutputTokens' => $maxTokens,
            ],
        ];

        if ($systemInstruction !== '') {
            $payload['system_instruction'] = [
                'parts' => [
                    ['text' => $systemInstruction],
                ],
            ];
        }

        if ($toolDefinitions !== []) {
            $declarations = $this->toolSchemaMapper->toFunctionDeclarations($toolDefinitions);
            if ($declarations !== []) {
                $payload['tools'] = [
                    ['functionDeclarations' => $declarations],
                ];
            }
        }

        try {
            $response = Http::timeout(30)
                ->retry(2, 300)
                ->withHeaders([
                    'x-goog-api-key' => $apiKey,
                ])
                ->post($endpoint, $payload)
                ->throw()
                ->json();
        } catch (RequestException|ConnectionException $exception) {
            $status = (int) ($exception instanceof RequestException ? ($exception->response?->status() ?? 0) : 0);
            $body = $exception instanceof RequestException
                ? (string) ($exception->response?->body() ?? '')
                : '';
            Log::warning('ai.google_ai_studio.generate_failed', [
                'status' => $status,
                'model' => $model,
                'message' => $exception->getMessage(),
            ]);

            return [
                'content' => '',
                'tokens_used' => 0,
                'raw' => [
                    'provider_error' => true,
                    'http_status' => $status,
                    'message' => $exception->getMessage(),
                    'body_preview' => $body !== '' ? mb_substr($body, 0, 800) : null,
                ],
            ];
        }

        if (! is_array($response)) {
            return [
                'content' => '',
                'tokens_used' => 0,
                'raw' => [],
            ];
        }

        $toolCalls = $this->toolResponseMapper->extractToolCalls($response);

        $parts = $response['candidates'][0]['content']['parts'] ?? [];
        $text = collect(is_array($parts) ? $parts : [])
            ->pluck('text')
            ->filter()
            ->implode("\n");

        $result = [
            'content' => (string) $text,
            'tokens_used' => (int) ($response['usageMetadata']['totalTokenCount'] ?? 0),
            'raw' => $response,
        ];

        if ($toolCalls !== []) {
            $result['tool_calls'] = $toolCalls;
        }

        return $result;
    }
}
