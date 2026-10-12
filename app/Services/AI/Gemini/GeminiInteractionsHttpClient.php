<?php

namespace App\Services\AI\Gemini;

use App\Services\AI\Contracts\GeminiInteractionsClient;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

final class GeminiInteractionsHttpClient implements GeminiInteractionsClient
{
    public function create(GeminiInteractionRequest $request): GeminiInteraction
    {
        $apiKey = (string) config('services.google_ai_studio.key');
        if ($apiKey === '') {
            throw new GeminiInteractionsException('Google AI Studio API key is not configured.');
        }

        $endpoint = rtrim((string) config('ai.google_ai_studio.base_url'), '/').'/interactions';

        try {
            $response = Http::timeout(30)
                ->retry(1, 300)
                ->withHeaders([
                    'x-goog-api-key' => $apiKey,
                    'Content-Type' => 'application/json',
                    'Api-Revision' => '2026-05-20',
                ])
                ->post($endpoint, $request->toPayload());
        } catch (RequestException $exception) {
            $status = (int) ($exception->response?->status() ?? 0);
            throw new GeminiInteractionsException(
                'Gemini interaction request failed.',
                $status,
                retryable: $status >= 500 || $status === 0,
            );
        } catch (\Throwable $exception) {
            throw new GeminiInteractionsException('Gemini interaction request failed.', 0, retryable: true);
        }

        $status = $response->status();
        if ($status >= 400) {
            throw new GeminiInteractionsException(
                'Gemini interaction request failed.',
                $status,
                retryable: $status >= 500,
            );
        }

        $payload = $response->json();
        if (! is_array($payload)) {
            throw new GeminiInteractionsException('Gemini returned an empty interaction.', $status);
        }

        return GeminiInteraction::fromResponse($payload);
    }
}
