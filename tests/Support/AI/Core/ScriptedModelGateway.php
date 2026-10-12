<?php

declare(strict_types=1);

namespace Tests\Support\AI\Core;

use App\AI\Core\Contracts\ModelGateway;
use App\AI\Core\Data\ModelRequest;
use App\AI\Core\Data\ModelResponse;
use RuntimeException;
use Throwable;

/**
 * Test-only gateway that replays a fixed script of responses or exceptions.
 */
final class ScriptedModelGateway implements ModelGateway
{
    /** @var list<ModelRequest> */
    public array $requests = [];

    /**
     * @param  list<ModelResponse|Throwable>  $script
     */
    public function __construct(public array $script = []) {}

    public function generate(ModelRequest $request): ModelResponse
    {
        $this->requests[] = $request;
        $next = array_shift($this->script) ?? throw new RuntimeException('Model script exhausted.');
        if ($next instanceof Throwable) {
            throw $next;
        }

        return $next;
    }
}
