<?php

declare(strict_types=1);

namespace App\AI\Core\Contracts;

use App\AI\Core\AiRequest;
use App\AI\Core\Data\ResolvedEmployee;
use App\AI\Core\Prompt\PromptSection;

interface PolicyProvider
{
    /**
     * Security and workspace business policy for this run. Read-only.
     *
     * @return list<PromptSection> Policy-layer sections only.
     */
    public function sections(AiRequest $request, ResolvedEmployee $employee): array;
}
