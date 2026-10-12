<?php

declare(strict_types=1);

namespace App\AI\Employees\Preview;

use App\AI\Constitution\ConstitutionLoader;
use App\AI\Context\AiContextProvider;
use App\AI\Core\AiInboundMessage;
use App\AI\Core\AiRequest;
use App\AI\Core\AiResponse;
use App\AI\Core\AiRunMode;
use App\AI\Core\AiRuntime;
use App\AI\Core\AiRuntimeLimits;
use App\AI\Core\Contracts\ModelGateway;
use App\AI\Core\Pipeline\ModelTurnLoop;
use App\AI\Core\Pipeline\PromptAssembler;
use App\AI\Core\PromptComposer;
use App\AI\Employees\DatabaseEmployeeResolver;
use App\AI\Employees\EmployeeTrainingQueryFactory;
use App\AI\Employees\Models\AiEmployee;
use App\AI\Training\LibraryTrainingProvider;
use App\AI\Training\TrainingContextBuilder;

/**
 * Runs one employee in preview mode: no conversation, no write tools, nothing sent or stored.
 * Model calls go through the canonical Hasim Model Gateway and that employee's settings.
 * Identity and persona come from the same employee resolver, context, and prompt layers as live runs.
 */
final class EmployeePreviewRunner
{
    public function __construct(
        private readonly DatabaseEmployeeResolver $employees,
        private readonly EmployeeTrainingQueryFactory $trainingQueries,
        private readonly ConstitutionLoader $constitution,
        private readonly TrainingContextBuilder $training,
        private readonly ModelGateway $gateway,
        private readonly AiContextProvider $context,
    ) {}

    public function run(AiEmployee $employee, string $message): AiResponse
    {
        $runtime = new AiRuntime(
            $this->employees,
            new PromptAssembler(
                $this->constitution,
                new PreviewPolicyProvider,
                $this->context,
                new LibraryTrainingProvider($this->training, $this->trainingQueries),
                new PromptComposer,
            ),
            new ModelTurnLoop($this->gateway, new PreviewToolRunner, new AiRuntimeLimits),
            new PreviewResponseGuard,
        );

        return $runtime->handle(new AiRequest(
            workspaceId: (int) $employee->workspace_id,
            conversationId: null,
            employeeId: (int) $employee->id,
            message: new AiInboundMessage($message),
            runMode: AiRunMode::Preview,
        ));
    }
}
