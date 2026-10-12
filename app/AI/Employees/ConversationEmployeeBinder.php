<?php

declare(strict_types=1);

namespace App\AI\Employees;

use App\Models\Conversation;

/**
 * Pins the employee a live run selected onto its conversation, once. Kept outside the
 * resolver because the core contract requires resolution to be read-only; callers bind
 * after a live run. Inbox live runs bind through HasimInboxRuntime.
 */
final class ConversationEmployeeBinder
{
    public function __construct(private readonly EmployeeRepository $employees) {}

    /** Returns true when this call created the binding; an existing binding is never replaced. */
    public function bindIfUnassigned(int $workspaceId, int $conversationId, int $employeeId): bool
    {
        if (! $this->employees->exists($workspaceId, $employeeId)) {
            return false;
        }

        return Conversation::withoutGlobalScopes()
            ->where('workspace_id', $workspaceId)
            ->whereKey($conversationId)
            ->whereNull('ai_employee_id')
            ->toBase()
            ->update(['ai_employee_id' => $employeeId]) === 1;
    }
}
