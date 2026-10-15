<?php

namespace App\Services\AI;

use App\AI\Inbox\HasimInboxRuntime;
use App\Exceptions\FeatureNotAvailableException;
use App\Exceptions\MissingWorkspaceContextException;
use App\Exceptions\UsageLimitExceededException;
use App\Jobs\UpdateAIUsage;
use App\Models\AiLog;
use App\Models\AiSetting;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Workspace;
use App\Services\Feature\FeatureAccessService;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class AIService
{
    public function __construct(
        private readonly FeatureAccessService $featureAccess,
        private readonly AiSafety $safety,
        private readonly AiUsageAccountant $usageAccountant,
        private readonly HasimInboxRuntime $hasimRuntime,
    ) {}

    public function generateReply(Conversation $conversation, Message $message): string
    {
        $workspace = Workspace::query()->find($conversation->workspace_id);
        if (! $workspace) {
            throw MissingWorkspaceContextException::forQuery(Conversation::class);
        }

        return app(WorkspaceContext::class)->runFor($workspace, function () use ($conversation, $message, $workspace): string {
            $this->assertAiFeature($workspace);
            $this->assertGenerationBudget($workspace);

            if ($this->inboxAiDisabled($conversation)) {
                return '';
            }

            return $this->finalizeHasimGeneration($workspace, $conversation, $message);
        });
    }

    private function inboxAiDisabled(Conversation $conversation): bool
    {
        $setting = AiSetting::withoutGlobalScopes()
            ->where('workspace_id', $conversation->workspace_id)
            ->first();

        return $setting !== null && $setting->is_active === false;
    }

    private function finalizeHasimGeneration(
        Workspace $workspace,
        Conversation $conversation,
        Message $message,
    ): string {
        $result = $this->hasimRuntime->reply($conversation, $message);
        $userContent = $this->safety->truncateInput((string) ($message->content ?? ''));
        $usage = $result->response?->decision->modelUsage;
        $tokensUsed = $usage?->totalTokens() ?? 0;
        $status = $result->deliverable ? 'success' : 'failed';
        $decision = $result->response?->decision;
        $failure = $decision?->failure;

        $logMeta = $this->safety->logMetadata(
            $usage?->provider ?? 'hasim',
            $usage?->model ?? 'hasim',
            mb_strlen($userContent),
            'pending',
            [
                'hasim_runtime' => true,
                'runtime_status' => $decision?->status->value,
                'employee_id' => $decision?->employeeId,
                'constitution_version' => $decision?->constitutionVersion,
                'intent' => $decision?->intent,
                'evaluation_status' => $result->evaluation?->status->value,
                'evaluation_score' => $result->evaluation?->score,
                'failure_stage' => $failure?->stage->value,
                'failure_code' => $failure?->code->value,
                'provider_error_code' => $failure?->providerErrorCode,
                'http_status' => $failure?->httpStatus,
                'guard_verdict' => $decision?->metadata['guard_verdict'] ?? null,
                'conversation_id' => $conversation->id,
            ],
        );

        $this->writeLog(
            $conversation,
            $message,
            $logMeta,
            $status,
            $tokensUsed,
            $result->deliverable ? ['content' => $result->reply] : [],
            $failure?->code->value,
            $decision?->employeeId,
        );

        if ($result->deliverable) {
            $this->recordUsage($workspace, $conversation, $message, $userContent, $tokensUsed);
        }

        return $result->reply;
    }

    private function recordUsage(
        Workspace $workspace,
        Conversation $conversation,
        Message $message,
        string $userContent,
        int $tokensUsed,
    ): void {
        $usageEventKey = 'aiservice:'.$conversation->id.':'.($message->exists ? (string) $message->id : 'ephemeral:'.sha1($userContent));
        $workspaceId = (int) $workspace->id;

        if (! $this->usageAccountant->claim($workspaceId, $usageEventKey)) {
            UpdateAIUsage::dispatch($workspace->id, 0, $usageEventKey);

            return;
        }

        try {
            $this->settleDeliverableUsage($workspace, $tokensUsed);
        } catch (Throwable $exception) {
            $this->usageAccountant->release($workspaceId, $usageEventKey);

            if ($exception instanceof UsageLimitExceededException) {
                Log::warning('ai.usage.accounting_failed', [
                    'workspace_id' => $workspaceId,
                    'conversation_id' => (int) $conversation->id,
                    'message_id' => $message->exists ? (int) $message->id : null,
                    'meter' => $exception->meter,
                    'limit' => $exception->limit,
                    'used' => $exception->used,
                    'phase' => UsageLimitExceededException::PHASE_AFTER_GENERATION,
                ]);

                throw new UsageLimitExceededException(
                    meter: $exception->meter,
                    limit: $exception->limit,
                    used: $exception->used,
                    overageBehavior: $exception->overageBehavior,
                    phase: UsageLimitExceededException::PHASE_AFTER_GENERATION,
                );
            }

            throw $exception;
        }

        UpdateAIUsage::dispatch($workspace->id, 0, $usageEventKey);
    }

    private function assertGenerationBudget(Workspace $workspace): void
    {
        $this->featureAccess->assertWithinLimit($workspace, 'ai_usage', 1);

        if ($this->featureAccess->hasExplicitLimit($workspace, 'ai_tokens')) {
            $this->featureAccess->assertWithinLimit($workspace, 'ai_tokens', 256);
        }
    }

    private function settleDeliverableUsage(Workspace $workspace, int $tokensUsed): void
    {
        $consumeTokens = $this->featureAccess->hasExplicitLimit($workspace, 'ai_tokens') && $tokensUsed > 0;

        if ($consumeTokens) {
            $this->featureAccess->assertWithinLimit($workspace, 'ai_tokens', $tokensUsed);
        }

        $this->featureAccess->assertWithinLimit($workspace, 'ai_usage', 1);

        DB::transaction(function () use ($workspace, $tokensUsed, $consumeTokens): void {
            $this->featureAccess->consumeUsage($workspace, 'ai_usage', 1, enforce: true);
            if ($consumeTokens) {
                $this->featureAccess->consumeUsage($workspace, 'ai_tokens', $tokensUsed, enforce: true);
            }
        });
    }

    /**
     * @param  array<string, mixed>  $meta
     * @param  array<string, mixed>  $raw
     */
    private function writeLog(
        Conversation $conversation,
        Message $message,
        array $meta,
        string $status,
        int $tokensUsed,
        array $raw,
        ?string $error = null,
        ?int $employeeId = null,
    ): void {
        $input = $meta;
        $input['outcome'] = $status;
        $input['request_id'] = request()?->header('X-Request-Id') ?: (string) Str::uuid();
        $output = [
            'content_chars' => $status === 'success'
                ? mb_strlen($this->safety->sanitizeReply((string) ($raw['content'] ?? '')))
                : 0,
        ];

        if ($this->safety->storesContent()) {
            $input['content_stored'] = true;
            $output['raw_redacted'] = $this->safety->redactSecrets(json_encode($raw, JSON_UNESCAPED_UNICODE) ?: '');
        }

        AiLog::withoutGlobalScopes()->create([
            'workspace_id' => $conversation->workspace_id,
            'conversation_id' => $conversation->id,
            'message_id' => $message->exists ? $message->id : null,
            'ai_employee_id' => $employeeId,
            'user_id' => auth()->id(),
            'type' => 'reply',
            'input_payload' => $input,
            'output_payload' => $output,
            'tokens_used' => $tokensUsed,
            'status' => $status,
            'error_message' => $error ? $this->safety->redactSecrets($error) : null,
        ]);
    }

    private function assertAiFeature(Workspace $workspace): void
    {
        $user = auth()->user();

        if ($user) {
            $this->featureAccess->assertFeature($user, $workspace, 'ai');

            return;
        }

        if (! $this->featureAccess->workspaceHasFeature($workspace, 'ai')) {
            throw new FeatureNotAvailableException(
                feature: 'ai',
                requiredPlan: $this->featureAccess->suggestedPlanForFeature('ai'),
                message: __('ميزة الذكاء الاصطناعي غير متاحة في باقتك الحالية.'),
            );
        }
    }
}
