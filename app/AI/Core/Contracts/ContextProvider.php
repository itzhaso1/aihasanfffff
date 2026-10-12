<?php

declare(strict_types=1);

namespace App\AI\Core\Contracts;

use App\AI\Core\AiRequest;
use App\AI\Core\Data\ContextPayload;
use App\AI\Core\Data\ResolvedEmployee;

interface ContextProvider
{
    /**
     * Builds bounded runtime context and may return a skip or hand-off directive.
     * Must be read-only: memory writes and state updates belong to callers after a live run.
     */
    public function build(AiRequest $request, ResolvedEmployee $employee): ContextPayload;
}
