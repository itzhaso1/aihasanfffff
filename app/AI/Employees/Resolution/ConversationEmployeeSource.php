<?php

declare(strict_types=1);

namespace App\AI\Employees\Resolution;

use App\AI\Core\AiRequest;
use App\AI\Employees\ConversationEmployeeBinder;
use App\Models\Conversation;

/**
 * The employee already bound to the conversation. Read-only; binding happens through
 * {@see ConversationEmployeeBinder} after a live run.
 */
final class ConversationEmployeeSource implements EmployeeCandidateSource
{
    public function candidate(AiRequest $request): ?int
    {
        if ($request->conversationId === null) {
            return null;
        }

        $employeeId = Conversation::withoutGlobalScopes()
            ->where('workspace_id', $request->workspaceId)
            ->whereKey($request->conversationId)
            ->value('ai_employee_id');

        return $employeeId === null ? null : (int) $employeeId;
    }

    public function allowsInactiveInSandbox(): bool
    {
        return false;
    }
}
