<?php

declare(strict_types=1);

namespace App\AI\Employees\Legacy;

final readonly class LegacyImportEntry
{
    public const CREATED = 'created';

    public const WOULD_CREATE = 'would_create';

    public const SKIPPED_EXISTING = 'skipped_existing';

    public const FAILED = 'failed';

    public function __construct(
        public int $workspaceId,
        public int $aiSettingId,
        public string $action,
        public ?string $name = null,
        public ?int $employeeId = null,
        public bool $becomesDefault = false,
        public ?string $error = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'workspace_id' => $this->workspaceId,
            'ai_setting_id' => $this->aiSettingId,
            'action' => $this->action,
            'name' => $this->name,
            'employee_id' => $this->employeeId,
            'becomes_default' => $this->becomesDefault,
            'error' => $this->error,
        ];
    }
}
