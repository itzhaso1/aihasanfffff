<?php

declare(strict_types=1);

namespace App\AI\Core\Data;

use App\AI\Core\AiRunMode;
use App\AI\Core\Prompt\PromptComposition;
use App\AI\Core\Support\TypedList;

/**
 * Provider-neutral model call. The gateway resolves provider, model and generation
 * settings from the employee id; the core never carries them.
 */
final readonly class ModelRequest
{
    /** @var list<ToolSpec> */
    public array $tools;

    /** @var list<ToolExchange> */
    public array $toolExchanges;

    /**
     * @param  list<ToolSpec>  $tools
     * @param  list<ToolExchange>  $toolExchanges  Earlier tool calls in this run, oldest first.
     */
    public function __construct(
        public int $employeeId,
        public AiRunMode $runMode,
        public PromptComposition $prompt,
        array $tools = [],
        array $toolExchanges = [],
    ) {
        $this->tools = TypedList::of($tools, ToolSpec::class, 'Model tools');
        $this->toolExchanges = TypedList::of($toolExchanges, ToolExchange::class, 'Tool exchanges');
    }
}
