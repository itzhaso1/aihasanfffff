<?php

declare(strict_types=1);

namespace Tests\Support\AI\Core;

use App\AI\Core\AiRequest;
use App\AI\Core\Contracts\TrainingProvider;
use App\AI\Core\Data\ContextPayload;
use App\AI\Core\Data\ResolvedEmployee;
use App\AI\Core\Data\TrainingSelection;
use App\AI\Core\Decision\TrainingVersion;
use App\AI\Core\Prompt\PromptLayer;
use App\AI\Core\Prompt\PromptSection;

final class FakeTrainingProvider implements TrainingProvider
{
    public int $calls = 0;

    public function select(AiRequest $request, ResolvedEmployee $employee, ContextPayload $context): TrainingSelection
    {
        $this->calls++;

        return new TrainingSelection(
            [new PromptSection(PromptLayer::Training, 'training.style', 'TRAINING-LAYER', 'rules/style.md', 'v2')],
            [new TrainingVersion('rules/style', 'v2')],
        );
    }
}
