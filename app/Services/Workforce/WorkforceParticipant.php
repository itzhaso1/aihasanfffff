<?php

declare(strict_types=1);

namespace App\Services\Workforce;

use App\Enums\WorkforceParticipantType;

final readonly class WorkforceParticipant
{
    /**
     * @var list<string>
     */
    public const ALLOWED_KEYS = [
        'type',
        'source_id',
        'workspace_id',
        'display_name',
        'status',
        'role',
        'avatar_path',
        'workspace_user_id',
        'worker_profile_id',
        'engagement_id',
        'ai_employee_id',
    ];

    public function __construct(
        public WorkforceParticipantType $type,
        public int $sourceId,
        public int $workspaceId,
        public string $displayName,
        public string $status,
        public ?string $role = null,
        public ?string $avatarPath = null,
        public ?int $workspaceUserId = null,
        public ?int $workerProfileId = null,
        public ?int $engagementId = null,
        public ?int $aiEmployeeId = null,
    ) {}

    /**
     * @return array{
     *     type: string,
     *     source_id: int,
     *     workspace_id: int,
     *     display_name: string,
     *     status: string,
     *     role: ?string,
     *     avatar_path: ?string,
     *     workspace_user_id: ?int,
     *     worker_profile_id: ?int,
     *     engagement_id: ?int,
     *     ai_employee_id: ?int
     * }
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type->value,
            'source_id' => $this->sourceId,
            'workspace_id' => $this->workspaceId,
            'display_name' => $this->displayName,
            'status' => $this->status,
            'role' => $this->role,
            'avatar_path' => $this->avatarPath,
            'workspace_user_id' => $this->workspaceUserId,
            'worker_profile_id' => $this->workerProfileId,
            'engagement_id' => $this->engagementId,
            'ai_employee_id' => $this->aiEmployeeId,
        ];
    }
}
