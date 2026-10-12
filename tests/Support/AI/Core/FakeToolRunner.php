<?php

declare(strict_types=1);

namespace Tests\Support\AI\Core;

use App\AI\Core\AiRequest;
use App\AI\Core\Contracts\ToolRunner;
use App\AI\Core\Data\ModelToolCall;
use App\AI\Core\Data\ResolvedEmployee;
use App\AI\Core\Data\ToolOutcome;
use App\AI\Core\Data\ToolSpec;
use App\AI\Core\Decision\ConfirmedAction;
use App\AI\Core\Decision\FactRecord;
use App\AI\Core\Decision\ToolCallRecord;
use Throwable;

final class FakeToolRunner implements ToolRunner
{
    /** @var list<ToolSpec> */
    public array $tools;

    public ?Throwable $throw = null;

    public ?ConfirmedAction $confirmedAction = null;

    /** @var list<ModelToolCall> */
    public array $executed = [];

    public function __construct()
    {
        $this->tools = [new ToolSpec('search_products', 'Search catalog products.', ['query' => ['type' => 'string']])];
    }

    public function availableTools(AiRequest $request, ResolvedEmployee $employee): array
    {
        return $this->tools;
    }

    public function run(AiRequest $request, ResolvedEmployee $employee, ModelToolCall $call): ToolOutcome
    {
        $this->executed[] = $call;
        if ($this->throw !== null) {
            throw $this->throw;
        }

        return new ToolOutcome(
            record: new ToolCallRecord($call->name, $call->callId, $call->arguments, true),
            modelPayload: ['count' => 1, 'products' => [['id' => 42, 'price' => 299]]],
            facts: [new FactRecord($call->name, 'item:42.stock', 3)],
            confirmedAction: $this->confirmedAction,
        );
    }
}
