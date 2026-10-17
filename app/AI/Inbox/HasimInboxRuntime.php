<?php

declare(strict_types=1);

namespace App\AI\Inbox;

use App\AI\Constitution\ConstitutionLoader;
use App\AI\Context\AiContextProvider;
use App\AI\Core\AiInboundMessage;
use App\AI\Core\AiRequest;
use App\AI\Core\AiResponse;
use App\AI\Core\AiRunMode;
use App\AI\Core\AiRuntime;
use App\AI\Core\AiRuntimeLimits;
use App\AI\Core\Contracts\ConstitutionProvider;
use App\AI\Core\Contracts\ContextProvider;
use App\AI\Core\Contracts\EmployeeResolver;
use App\AI\Core\Contracts\ModelGateway;
use App\AI\Core\Contracts\PolicyProvider;
use App\AI\Core\Contracts\ResponseGuard;
use App\AI\Core\Contracts\ToolRunner;
use App\AI\Core\Contracts\TrainingProvider;
use App\AI\Core\Data\ModelFinishReason;
use App\AI\Core\InvalidAiRequest;
use App\AI\Core\Pipeline\ModelTurnLoop;
use App\AI\Core\Pipeline\PromptAssembler;
use App\AI\Core\PromptComposer;
use App\AI\Employees\ConversationEmployeeBinder;
use App\AI\Employees\DatabaseEmployeeResolver;
use App\AI\Employees\EmployeePersonaOptions;
use App\AI\Employees\EmployeeRepository;
use App\AI\Employees\EmployeeTrainingQueryFactory;
use App\AI\Evaluation\EvaluationExpectations;
use App\AI\Evaluation\EvaluationRequest;
use App\AI\Evaluation\EvaluationResult;
use App\AI\Evaluation\HasimEvaluator;
use App\AI\Guard\HasimResponseGuard;
use App\AI\Models\HasimModelGateway;
use App\AI\Tools\Adapters\InboxToolAdapters;
use App\AI\Tools\DatabaseToolPermissionSource;
use App\AI\Tools\HasimToolRunner;
use App\AI\Tools\ToolPolicy;
use App\AI\Training\LibraryTrainingProvider;
use App\AI\Training\TrainingContextBuilder;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\AI\AiSafety;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Inbox adapter for the Hasim Runtime. It turns a conversation message into an
 * {@see AiRequest}, runs the canonical pipeline, binds the resolved employee, and
 * evaluates the guarded result. It does not send messages or fall back to legacy AI.
 */
final class HasimInboxRuntime
{
    public function __construct(
        private readonly EmployeeResolver $employees,
        private readonly ConstitutionProvider $constitution,
        private readonly PolicyProvider $policy,
        private readonly ContextProvider $context,
        private readonly TrainingProvider $training,
        private readonly ModelGateway $gateway,
        private readonly ToolRunner $tools,
        private readonly ResponseGuard $guard,
        private readonly ConversationEmployeeBinder $binder,
        private readonly HasimEvaluator $evaluator,
        private readonly EmployeeRepository $employeeRecords,
        private readonly AiSafety $safety,
        private readonly AiRuntimeLimits $limits = new AiRuntimeLimits,
    ) {}

    public static function fromApplication(Container $app): self
    {
        $adapters = $app->make(InboxToolAdapters::class);

        return new self(
            $app->make(DatabaseEmployeeResolver::class),
            $app->make(ConstitutionLoader::class),
            new InboxPolicyProvider,
            $app->make(AiContextProvider::class),
            new LibraryTrainingProvider(
                $app->make(TrainingContextBuilder::class),
                $app->make(EmployeeTrainingQueryFactory::class),
            ),
            $app->make(HasimModelGateway::class),
            new HasimToolRunner(
                $adapters->registry(),
                new ToolPolicy,
                $app->make(DatabaseToolPermissionSource::class),
            ),
            new HasimResponseGuard,
            $app->make(ConversationEmployeeBinder::class),
            new HasimEvaluator,
            $app->make(EmployeeRepository::class),
            $app->make(AiSafety::class),
        );
    }

    public function reply(Conversation $conversation, Message $message): HasimInboxResult
    {
        try {
            $request = $this->requestFrom($conversation, $message);
        } catch (InvalidAiRequest) {
            Log::warning('hasim.inbox.invalid_request', [
                'workspace_id' => $conversation->workspace_id,
                'conversation_id' => $conversation->id,
                'message_id' => $message->exists ? $message->id : null,
            ]);

            return HasimInboxResult::undeliverable();
        }

        $response = $this->runtime()->handle($request);
        $this->bindEmployee($conversation, $response);
        $evaluation = $this->evaluate($request, $response);

        if (! $response->isDeliverable()) {
            Log::warning('hasim.inbox.failed', [
                'workspace_id' => $conversation->workspace_id,
                'conversation_id' => $conversation->id,
                'message_id' => $message->exists ? $message->id : null,
                'runtime_status' => $response->status->value,
                'employee_id' => $response->decision->employeeId,
                'failure' => $response->decision->failure?->toArray(),
            ]);
        }

        return HasimInboxResult::fromRuntime($request, $response, $evaluation);
    }

    private function requestFrom(Conversation $conversation, Message $message): AiRequest
    {
        $text = $this->safety->truncateInput((string) ($message->content ?? ''));
        $workspaceId = (int) $conversation->workspace_id;

        return new AiRequest(
            workspaceId: $workspaceId,
            conversationId: (int) $conversation->id,
            employeeId: null,
            message: new AiInboundMessage(
                $text,
                $message->exists ? (int) $message->id : null,
            ),
            runMode: AiRunMode::Live,
            channel: is_string($conversation->channel) && $conversation->channel !== ''
                ? $conversation->channel
                : null,
            attributes: $this->attributes($workspaceId, $conversation),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function attributes(int $workspaceId, Conversation $conversation): array
    {
        $boundId = $conversation->ai_employee_id !== null ? (int) $conversation->ai_employee_id : null;
        $employee = $boundId !== null
            ? $this->employeeRecords->find($workspaceId, $boundId)
            : $this->employeeRecords->findDefault($workspaceId);
        $persona = $employee?->persona;

        return array_filter([
            'employee_role' => $employee?->role,
            'employee_language' => $this->personaValue($persona?->language),
            'employee_dialect' => $this->personaValue($persona?->dialect),
        ], static fn (mixed $value): bool => $value !== null && $value !== '');
    }

    private function personaValue(?string $value): ?string
    {
        if ($value === null || $value === '' || $value === EmployeePersonaOptions::AUTO) {
            return null;
        }

        return $value;
    }

    private function bindEmployee(Conversation $conversation, AiResponse $response): void
    {
        $employeeId = $response->decision->employeeId;
        if ($employeeId === null) {
            return;
        }

        $this->binder->syncBinding(
            (int) $conversation->workspace_id,
            (int) $conversation->id,
            $employeeId,
        );
    }

    private function evaluate(AiRequest $request, AiResponse $response): ?EvaluationResult
    {
        try {
            $finish = $response->decision->metadata['finish_reason'] ?? null;

            return $this->evaluator->evaluate(EvaluationRequest::fromResponse(
                $response,
                $this->expectations((int) $request->workspaceId, $response),
                $request->message->text,
                is_string($finish) ? ModelFinishReason::tryFrom($finish) : null,
            ));
        } catch (Throwable $exception) {
            Log::warning('hasim.inbox.evaluation_failed', [
                'workspace_id' => $request->workspaceId,
                'conversation_id' => $request->conversationId,
                'exception_type' => $exception::class,
            ]);

            return null;
        }
    }

    private function expectations(int $workspaceId, AiResponse $response): EvaluationExpectations
    {
        $employeeId = $response->decision->employeeId;
        $employee = $employeeId !== null ? $this->employeeRecords->find($workspaceId, $employeeId) : null;
        $persona = $employee?->persona;

        return new EvaluationExpectations(
            language: $this->personaValue($persona?->language),
            dialect: $this->personaValue($persona?->dialect),
            greetingPolicy: $this->personaValue($persona?->greeting_policy),
            skillKeys: $employee?->skillKeys() ?? [],
            requiredTrainingIds: array_map(
                static fn ($version): string => $version->id,
                $response->decision->trainingVersions,
            ),
        );
    }

    private function runtime(): AiRuntime
    {
        return new AiRuntime(
            $this->employees,
            new PromptAssembler(
                $this->constitution,
                $this->policy,
                $this->context,
                $this->training,
                new PromptComposer,
            ),
            new ModelTurnLoop($this->gateway, $this->tools, $this->limits),
            $this->guard,
        );
    }
}
