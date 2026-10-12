<?php

namespace App\Console\Commands;

use App\AI\Training\TrainingException;
use App\AI\Training\TrainingRepository;
use Illuminate\Console\Command;

class AiTrainingIndexCommand extends Command
{
    protected $signature = 'ai:training:index {--json : Print the index summary as JSON}';

    protected $description = 'Rebuild the AI training index from source and print its summary (read-only)';

    public function handle(TrainingRepository $repository): int
    {
        try {
            $index = $repository->refresh();
        } catch (TrainingException $exception) {
            $this->error('Training library failed to load: '.$exception->getMessage());

            return self::FAILURE;
        }

        $summary = [
            'library_version' => $index->version(),
            'documents' => count($index->all()),
            'types' => $index->typeCounts(),
            'selectors' => $index->selectorCounts(),
        ];

        if ($this->option('json')) {
            $this->line(json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $this->info('Library version: '.$summary['library_version']);
        $this->line('Documents: '.$summary['documents']);
        $this->table(['Type', 'Documents'], array_map(null, array_keys($summary['types']), array_values($summary['types'])));
        foreach ($summary['selectors'] as $dimension => $values) {
            $this->line($dimension.': '.implode(', ', array_map(static fn (string $value, int $count): string => "{$value} ({$count})", array_keys($values), $values)));
        }

        return self::SUCCESS;
    }
}
