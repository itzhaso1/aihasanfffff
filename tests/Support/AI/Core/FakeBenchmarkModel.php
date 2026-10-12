<?php

declare(strict_types=1);

namespace Tests\Support\AI\Core;

use App\AI\Core\Contracts\ModelGateway;
use App\AI\Core\Data\ModelFinishReason;
use App\AI\Core\Data\ModelRequest;
use App\AI\Core\Data\ModelResponse;
use App\AI\Core\Data\ModelToolCall;
use App\AI\Core\Decision\ModelUsage;

/**
 * Deterministic stand-in for the model gateway. It never calls a provider.
 */
final class FakeBenchmarkModel implements ModelGateway
{
    /** @var list<ModelRequest> */
    public array $requests = [];

    public function __construct(private readonly bool $emitToolCall = false) {}

    public function generate(ModelRequest $request): ModelResponse
    {
        $this->requests[] = $request;
        $question = (string) ($request->prompt->userMessage()?->content ?? '');
        if ($this->emitToolCall && $request->tools !== [] && $request->toolExchanges === []) {
            return new ModelResponse(null, [
                new ModelToolCall('bench-tool-1', 'search_products', ['query' => $question]),
            ], ModelFinishReason::ToolCalls);
        }

        return new ModelResponse(
            'فحص بنية: '.substr(hash('sha256', $question), 0, 12),
            [],
            ModelFinishReason::Stop,
            new ModelUsage('fake_benchmark', 'fake-benchmark-model', 1, 1),
        );
    }
}
