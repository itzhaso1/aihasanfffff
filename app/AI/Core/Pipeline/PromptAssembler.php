<?php

declare(strict_types=1);

namespace App\AI\Core\Pipeline;

use App\AI\Core\AiDirective;
use App\AI\Core\AiFailureCode;
use App\AI\Core\AiRequest;
use App\AI\Core\AiRuntimeStage;
use App\AI\Core\Contracts\ConstitutionProvider;
use App\AI\Core\Contracts\ContextProvider;
use App\AI\Core\Contracts\PolicyProvider;
use App\AI\Core\Contracts\TrainingProvider;
use App\AI\Core\Data\ResolvedEmployee;
use App\AI\Core\Prompt\PromptSection;
use App\AI\Core\PromptComposer;

/**
 * Collects every prompt layer from its owning provider and hands them to the composer.
 */
final class PromptAssembler
{
    public function __construct(
        private readonly ConstitutionProvider $constitution,
        private readonly PolicyProvider $policy,
        private readonly ContextProvider $context,
        private readonly TrainingProvider $training,
        private readonly PromptComposer $composer,
    ) {}

    public function assemble(AiRequest $request, ResolvedEmployee $employee): AssembledPrompt
    {
        $constitution = AiStageFailure::wrap(
            AiRuntimeStage::LoadConstitution,
            fn () => $this->constitution->load($request),
        );
        $policy = AiStageFailure::wrap(
            AiRuntimeStage::LoadPolicy,
            fn (): array => $this->policy->sections($request, $employee),
        );
        $context = AiStageFailure::wrap(
            AiRuntimeStage::BuildContext,
            fn () => $this->context->build($request, $employee),
        );

        if ($context->directive !== AiDirective::Respond) {
            return AssembledPrompt::directed($constitution->version, $context);
        }

        $training = AiStageFailure::wrap(
            AiRuntimeStage::ResolveTraining,
            fn () => $this->training->select($request, $employee, $context),
        );
        $composition = AiStageFailure::wrap(
            AiRuntimeStage::ComposePrompt,
            fn () => $this->composer->compose(
                constitution: $constitution->sections,
                policy: $policy,
                employee: $employee->sections,
                training: $training->sections,
                context: $context->sections,
                userMessage: PromptSection::userMessage($request->message->text),
            ),
            AiFailureCode::InvalidPrompt,
        );

        return AssembledPrompt::composed($composition, $constitution->version, $training->versions, $context);
    }
}
