<?php

namespace App\Services\Finance\Assistant\Security;

use Illuminate\Support\Facades\Log;

final class FinanceAgentSecurityEventLogger
{
    /**
     * @param  array<string, scalar|null>  $context
     */
    public function log(string $event, FinanceAgentSecurityContext $security, array $context = []): void
    {
        Log::info('finance.ai.'.$event, array_merge([
            'workspace_id' => $security->workspaceId,
            'user_id' => $security->userId,
            'conversation_id' => $security->conversationId,
            'correlation_id' => $security->correlationId,
        ], $context));
    }
}
