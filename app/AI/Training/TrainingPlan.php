<?php

declare(strict_types=1);

namespace App\AI\Training;

use App\AI\Core\Data\TrainingSelection;
use App\AI\Core\Decision\TrainingVersion;
use App\AI\Core\Prompt\PromptLayer;
use App\AI\Core\Prompt\PromptSection;

/**
 * Result of one selection: chosen documents in prompt order, documents dropped by the
 * budget, and the selection version.
 */
final readonly class TrainingPlan
{
    public const SECTION_KEY_PREFIX = 'training.';

    public const SOURCE_PREFIX = 'training/';

    /**
     * @param  list<TrainingDocument>  $documents
     * @param  list<string>  $omittedIds  Matching documents that did not fit the budget.
     */
    public function __construct(
        public array $documents,
        public array $omittedIds,
        public int $usedChars,
        public int $budgetChars,
    ) {}

    public function version(): string
    {
        return TrainingSelectionVersion::of($this->documents);
    }

    /**
     * @return list<string>
     */
    public function ids(): array
    {
        return array_map(static fn (TrainingDocument $document): string => $document->id, $this->documents);
    }

    public function toSelection(): TrainingSelection
    {
        $sections = [];
        $versions = [];
        foreach ($this->documents as $document) {
            $sections[] = new PromptSection(
                layer: PromptLayer::Training,
                key: self::SECTION_KEY_PREFIX.$document->id,
                content: $document->content,
                source: self::SOURCE_PREFIX.$document->source,
                version: $document->versionLabel(),
            );
            $versions[] = new TrainingVersion($document->id, $document->versionLabel());
        }

        return new TrainingSelection($sections, $versions);
    }
}
