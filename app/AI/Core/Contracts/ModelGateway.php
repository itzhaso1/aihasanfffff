<?php

declare(strict_types=1);

namespace App\AI\Core\Contracts;

use App\AI\Core\Data\ModelRequest;
use App\AI\Core\Data\ModelResponse;

interface ModelGateway
{
    /**
     * One model turn. Implementations own provider selection, wire formats and error
     * normalization; any throwable is treated by the core as a failed model call.
     */
    public function generate(ModelRequest $request): ModelResponse;
}
