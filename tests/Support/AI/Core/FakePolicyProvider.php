<?php

declare(strict_types=1);

namespace Tests\Support\AI\Core;

use App\AI\Core\AiRequest;
use App\AI\Core\Contracts\PolicyProvider;
use App\AI\Core\Data\ResolvedEmployee;
use App\AI\Core\Prompt\PromptLayer;
use App\AI\Core\Prompt\PromptSection;

final class FakePolicyProvider implements PolicyProvider
{
    /** @var list<PromptSection> */
    public array $sections;

    public function __construct()
    {
        $this->sections = [new PromptSection(PromptLayer::Policy, 'policy.workspace', 'POLICY-LAYER')];
    }

    public function sections(AiRequest $request, ResolvedEmployee $employee): array
    {
        return $this->sections;
    }
}
