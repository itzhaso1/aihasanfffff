<?php

namespace App\Services\AI;

use App\AI\Inbox\HasimInboxRuntime;
use App\Exceptions\FeatureNotAvailableException;
use App\Exceptions\MissingWorkspaceContextException;
use App\Jobs\UpdateAIUsage;
use App\Models\AiLog;
use App\Models\AiSetting;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Workspace;
use App\Services\Feature\FeatureAccessService;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Support\Str;

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
            $this->featureAccess->assertWithinLimit($workspace, 'ai_usage', 1);
            $this->featureAccess->assertWithinLimit($workspace, 'ai_tokens', 256);

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
        if ($this->usageAccountant->claim((int) $workspace->id, $usageEventKey)) {
            $this->featureAccess->consumeUsage($workspace, 'ai_usage', 1, enforce: true);
            if ($tokensUsed > 0) {
                $this->featureAccess->consumeUsage($workspace, 'ai_tokens', $tokensUsed, enforce: true);
            }
        }
        UpdateAIUsage::dispatch($workspace->id, 0, $usageEventKey);
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
