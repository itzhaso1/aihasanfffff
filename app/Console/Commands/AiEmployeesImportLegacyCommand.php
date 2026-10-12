<?php

namespace App\Console\Commands;

use App\AI\Employees\Legacy\LegacyAiSettingsImporter;
use App\AI\Employees\Legacy\LegacyImportEntry;
use Illuminate\Console\Command;

class AiEmployeesImportLegacyCommand extends Command
{
    protected $signature = 'ai:employees:import-legacy
        {--dry-run : Show what would be created without writing anything}
        {--workspace= : Only import this workspace id}';

    protected $description = 'Create one AI employee per legacy ai_settings row (idempotent; never modifies ai_settings)';

    public function handle(LegacyAiSettingsImporter $importer): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $workspace = $this->option('workspace');
        $entries = $importer->import($dryRun, $workspace !== null && $workspace !== '' ? (int) $workspace : null);

        $this->table(
            ['Workspace', 'ai_settings', 'Action', 'Employee', 'Name', 'Default', 'Error'],
            array_map(static fn (LegacyImportEntry $entry): array => [
                $entry->workspaceId,
                $entry->aiSettingId,
                $entry->action,
                $entry->employeeId ?? '-',
                $entry->name ?? '-',
                $entry->becomesDefault ? 'yes' : 'no',
                $entry->error ?? '',
            ], $entries),
        );

        $failed = count(array_filter($entries, static fn (LegacyImportEntry $entry): bool => $entry->action === LegacyImportEntry::FAILED));
        $this->info(($dryRun ? 'Dry run: ' : '').count($entries).' ai_settings row(s) processed, '.$failed.' failed.');

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
