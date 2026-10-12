<?php

namespace App\Jobs;

use App\Models\AiLog;
use App\Models\Workspace;
use App\Services\AI\AiUsageAccountant;
use App\Services\Feature\FeatureAccessService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class UpdateAIUsage implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public readonly string $usageEventKey;

    public function __construct(
        public readonly int $workspaceId,
        public readonly int|float $tokens = 0,
        ?string $usageEventKey = null,
    ) {
        $trimmed = trim((string) $usageEventKey);
        $this->usageEventKey = $trimmed !== '' ? $trimmed : 'job:'.Str::uuid();
    }

    public function handle(FeatureAccessService $featureAccess, ?AiUsageAccountant $usageAccountant = null): void
    {
        $usageAccountant ??= app(AiUsageAccountant::class);

        $usage = AiLog::withoutGlobalScopes()
            ->where('workspace_id', $this->workspaceId)
            ->sum('tokens_used');

        Cache::put('workspace:'.$this->workspaceId.':ai_usage_tokens', $usage, now()->addHours(2));

        $workspace = Workspace::withoutGlobalScopes()->find($this->workspaceId);
        if (! $workspace) {
            return;
        }

        if ($this->tokens <= 0) {
            return;
        }

        if (! $usageAccountant->claim($this->workspaceId, $this->usageEventKey)) {
            return;
        }

        $featureAccess->consumeUsage($workspace, 'ai_tokens', $this->tokens, enforce: false);
        $featureAccess->consumeUsage($workspace, 'ai_usage', 1, enforce: false);
    }
}
