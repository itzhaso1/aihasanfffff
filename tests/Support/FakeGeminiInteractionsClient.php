<?php

namespace Tests\Support;

use App\Services\AI\Contracts\GeminiInteractionsClient;
use App\Services\AI\Gemini\GeminiFunctionCall;
use App\Services\AI\Gemini\GeminiInteraction;
use App\Services\AI\Gemini\GeminiInteractionRequest;
use App\Services\AI\Gemini\GeminiInteractionsException;
use App\Services\AI\Gemini\GeminiUsage;
use Closure;

final class FakeGeminiInteractionsClient implements GeminiInteractionsClient
{
    /** @var list<GeminiInteraction|Closure(GeminiInteractionRequest): GeminiInteraction> */
    public array $script = [];

    /** @var list<GeminiInteractionRequest> */
    public array $requests = [];

    public bool $throwOnCreate = false;

    public bool $throwOnPreviousInteraction = false;

    public ?string $rejectPreviousId = null;

    public int $failAfter = 0;

    public function queue(GeminiInteraction|Closure $interaction): self
    {
        $this->script[] = $interaction;

        return $this;
    }

    public function create(GeminiInteractionRequest $request): GeminiInteraction
    {
        $this->requests[] = $request;

        if ($this->throwOnCreate) {
            throw new GeminiInteractionsException('Gemini interaction request failed.', 503, retryable: true);
        }

        if ($this->throwOnPreviousInteraction && filled($request->previousInteractionId)) {
            throw new GeminiInteractionsException('Gemini interaction request failed.', 400, retryable: false);
        }

        if ($this->rejectPreviousId !== null && $request->previousInteractionId === $this->rejectPreviousId) {
            throw new GeminiInteractionsException('Gemini interaction request failed.', 400, retryable: false);
        }

        if ($this->failAfter > 0 && count($this->requests) > $this->failAfter) {
            throw new GeminiInteractionsException('Gemini interaction request failed.', 503, retryable: true);
        }

        $next = array_shift($this->script);
        if ($next instanceof Closure) {
            return $next($request);
        }
        if ($next instanceof GeminiInteraction) {
            return $next;
        }

        return self::completed('done', 'لا أستطيع تأكيد هذا الرقم من السجلات المتاحة.');
    }

    /**
     * @param  list<array{name: string, arguments?: array<string, mixed>, id?: string}>  $calls
     */
    public static function requiring(string $id, array $calls, ?GeminiUsage $usage = null): GeminiInteraction
    {
        $functionCalls = [];
        foreach ($calls as $index => $call) {
            $functionCalls[] = new GeminiFunctionCall(
                id: (string) ($call['id'] ?? $id.'-'.$index),
                name: (string) $call['name'],
                arguments: is_array($call['arguments'] ?? null) ? $call['arguments'] : [],
            );
        }

        return new GeminiInteraction(
            id: $id,
            status: 'requires_action',
            outputText: '',
            functionCalls: $functionCalls,
            usage: $usage ?? new GeminiUsage,
            raw: ['id' => $id],
        );
    }

    public static function completed(string $id, string $text, ?GeminiUsage $usage = null): GeminiInteraction
    {
        return new GeminiInteraction(
            id: $id,
            status: 'completed',
            outputText: $text,
            functionCalls: [],
            usage: $usage ?? new GeminiUsage,
            raw: ['id' => $id, 'output_text' => $text],
        );
    }
}
