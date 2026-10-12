<?php

declare(strict_types=1);

namespace App\AI\Context;

use App\AI\Core\AiRequest;
use App\AI\Core\Contracts\ContextProvider;
use App\AI\Core\Data\ContextPayload;
use App\AI\Core\Data\ResolvedEmployee;

final class AiContextProvider implements ContextProvider
{
    public function __construct(
        private readonly AiContextBuilder $builder,
        private readonly AiContextRenderer $renderer,
    ) {}

    public function build(AiRequest $request, ResolvedEmployee $employee): ContextPayload
    {
        $context = $this->builder->build($request, $employee);

        return new ContextPayload(
            sections: $this->renderer->sections($context),
            intent: $context->intent->canonical,
            references: $context->references->references,
            structuredContext: $context,
        );
    }
}
