<?php

namespace App\Console\Commands;

use App\AI\Training\TrainingLinter;
use App\AI\Training\TrainingLintIssue;
use Illuminate\Console\Command;

class AiTrainingLintCommand extends Command
{
    protected $signature = 'ai:training:lint {--json : Print the report as JSON}';

    protected $description = 'Validate the AI training library (read-only; never edits training files)';

    public function handle(TrainingLinter $linter): int
    {
        $report = $linter->lint();

        if ($this->option('json')) {
            $this->line(json_encode([
                'passed' => $report->passed(),
                'documents' => $report->documentCount,
                'issues' => array_map(static fn (TrainingLintIssue $issue): array => $issue->toArray(), $report->issues),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return $report->passed() ? self::SUCCESS : self::FAILURE;
        }

        foreach ($report->issues as $issue) {
            $this->error(sprintf('[%s] %s%s', $issue->code->value, $issue->source !== null ? $issue->source.': ' : '', $issue->message));
        }

        if ($report->passed()) {
            $this->info("Training library is valid ({$report->documentCount} documents).");

            return self::SUCCESS;
        }

        $this->error(count($report->issues)." issue(s) found in {$report->documentCount} valid documents.");

        return self::FAILURE;
    }
}
