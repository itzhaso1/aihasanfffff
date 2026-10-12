<?php

declare(strict_types=1);

namespace App\AI\Employees\Legacy;

use App\AI\Employees\EmployeeOperationException;
use App\AI\Employees\EmployeeRepository;
use App\AI\Employees\EmployeeService;
use App\AI\Employees\EmployeeStatus;
use App\AI\Employees\InvalidEmployeeData;
use App\AI\Employees\Models\AiEmployee;
use App\Models\AiSetting;
use App\Models\Workspace;
use App\Services\AI\Inbox\Tools\Contracts\InboxAgentTool;
use App\Services\AI\Inbox\Tools\InboxAgentToolRegistry;

/**
 * Creates one employee per legacy ai_settings row. Idempotent: a row that already has an
 * employee (including a soft-deleted one) is skipped. ai_settings is only read.
 * The imported employee becomes the workspace default only when the workspace has no
 * default yet and the legacy assistant was active.
 */
final class LegacyAiSettingsImporter
{
    public function __construct(
        private readonly LegacyAiSettingsBridge $bridge,
        private readonly EmployeeService $service,
        private readonly EmployeeRepository $employees,
        private readonly InboxAgentToolRegistry $tools,
    ) {}

    /**
     * @return list<LegacyImportEntry>
     */
    public function import(bool $dryRun = false, ?int $workspaceId = null): array
    {
        $settings = AiSetting::withoutGlobalScopes()
            ->when($workspaceId !== null, fn ($query) => $query->where('workspace_id', $workspaceId))
            ->orderBy('id')
            ->get();

        $entries = [];
        foreach ($settings as $setting) {
            $entries[] = $this->importOne($setting, $dryRun);
        }

        return $entries;
    }

    private function importOne(AiSetting $setting, bool $dryRun): LegacyImportEntry
    {
        $workspaceId = (int) $setting->workspace_id;
        $existing = AiEmployee::queryForWorkspace($workspaceId)
            ->withTrashed()
            ->where('legacy_ai_setting_id', $setting->id)
            ->first();
        if ($existing !== null) {
            return new LegacyImportEntry($workspaceId, (int) $setting->id, LegacyImportEntry::SKIPPED_EXISTING, $existing->name, (int) $existing->id);
        }

        $workspace = Workspace::query()->find($workspaceId);
        if ($workspace === null) {
            return new LegacyImportEntry($workspaceId, (int) $setting->id, LegacyImportEntry::FAILED, error: 'workspace_missing');
        }

        try {
            $draft = $this->bridge->draftFromSetting($setting, $this->toolNames());
        } catch (InvalidEmployeeData $exception) {
            return new LegacyImportEntry($workspaceId, (int) $setting->id, LegacyImportEntry::FAILED, error: $exception->field);
        }

        $becomesDefault = $draft->status === EmployeeStatus::Active && ! $this->employees->hasDefault($workspaceId);
        if ($dryRun) {
            return new LegacyImportEntry($workspaceId, (int) $setting->id, LegacyImportEntry::WOULD_CREATE, $draft->name, null, $becomesDefault);
        }

        try {
            $employee = $this->service->create($workspace, $draft);
        } catch (InvalidEmployeeData|EmployeeOperationException $exception) {
            return new LegacyImportEntry($workspaceId, (int) $setting->id, LegacyImportEntry::FAILED, $draft->name, error: $exception->getMessage());
        }

        return new LegacyImportEntry($workspaceId, (int) $setting->id, LegacyImportEntry::CREATED, $employee->name, (int) $employee->id, $employee->isDefault());
    }

    /**
     * @return list<string>
     */
    private function toolNames(): array
    {
        return array_map(static fn (InboxAgentTool $tool): string => $tool->name(), $this->tools->all());
    }
}
