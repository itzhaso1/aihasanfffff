<?php

declare(strict_types=1);

namespace App\AI\Training;

use App\AI\Core\Data\TrainingSelection;

/**
 * Selects the training relevant to one run, never the whole library.
 *
 * Tiers, in order: 1 always-on rules, 2 the requested profile's includes, 3–8 documents
 * matching skills, dialect, language, intent, role, domain (a document sits in the tier
 * of its first matching dimension), 9 examples and scenarios matching the run.
 * Inside a tier: priority, then specificity (matched dimensions), then id. Documents
 * are added in that order while they fit the character budget; always-on rules must fit.
 */
final class TrainingContextBuilder
{
    public const ALWAYS_TIER = 1;

    public const PROFILE_TIER = 2;

    public const SITUATIONAL_TIER = 9;

    private const DIMENSION_TIER_OFFSET = 2;

    private const PROFILE_ID_PREFIX = 'profile.';

    public function __construct(
        private readonly TrainingRepository $repository = new TrainingRepository,
    ) {}

    public function build(TrainingQuery $query): TrainingSelection
    {
        return $this->plan($query)->toSelection();
    }

    public function plan(TrainingQuery $query): TrainingPlan
    {
        $index = $this->repository->index();
        $candidates = [
            ...$this->alwaysRules($index),
            ...$this->profileDocuments($index, $query),
            ...$this->matchedDocuments($index, $query),
        ];

        return $this->fit($this->unique($candidates), $query->budgetChars);
    }

    /**
     * @return list<TrainingCandidate>
     */
    private function alwaysRules(TrainingIndex $index): array
    {
        $rules = array_filter($index->ofType(TrainingType::Rule), static fn (TrainingDocument $document): bool => $document->always);
        $candidates = array_map(static fn (TrainingDocument $document): TrainingCandidate => new TrainingCandidate($document, self::ALWAYS_TIER), array_values($rules));

        return $this->sorted($candidates);
    }

    /**
     * @return list<TrainingCandidate>
     */
    private function profileDocuments(TrainingIndex $index, TrainingQuery $query): array
    {
        if ($query->profile === null) {
            return [];
        }
        $profile = $index->find(self::PROFILE_ID_PREFIX.$query->profile);
        if ($profile === null || $profile->type !== TrainingType::Profile) {
            throw TrainingException::unknownProfile(self::PROFILE_ID_PREFIX.$query->profile);
        }

        $candidates = [];
        foreach ($profile->includes as $id) {
            $document = $index->find($id);
            if ($document !== null) {
                $candidates[] = new TrainingCandidate($document, self::PROFILE_TIER);
            }
        }

        return $candidates;
    }

    /**
     * @return list<TrainingCandidate>
     */
    private function matchedDocuments(TrainingIndex $index, TrainingQuery $query): array
    {
        $candidates = [];
        foreach ($index->all() as $document) {
            if ($document->always || ! $document->type->isPromptContent()) {
                continue;
            }
            $matched = $document->appliesTo->matchedDimensions($query);
            if ($matched === null) {
                continue;
            }
            $tier = $document->type->isSituational()
                ? self::SITUATIONAL_TIER
                : self::DIMENSION_TIER_OFFSET + $matched[0]->rank();
            $candidates[] = new TrainingCandidate($document, $tier, count($matched));
        }

        return $this->sorted($candidates);
    }

    /**
     * @param  list<TrainingCandidate>  $candidates
     * @return list<TrainingCandidate>
     */
    private function sorted(array $candidates): array
    {
        usort($candidates, static fn (TrainingCandidate $a, TrainingCandidate $b): int => [
            $a->tier,
            -$a->document->priority->weight(),
            -$a->specificity,
            $a->document->id,
        ] <=> [
            $b->tier,
            -$b->document->priority->weight(),
            -$b->specificity,
            $b->document->id,
        ]);

        return $candidates;
    }

    /**
     * @param  list<TrainingCandidate>  $candidates
     * @return list<TrainingCandidate>
     */
    private function unique(array $candidates): array
    {
        $seen = [];
        $unique = [];
        foreach ($candidates as $candidate) {
            if (! isset($seen[$candidate->document->id])) {
                $seen[$candidate->document->id] = true;
                $unique[] = $candidate;
            }
        }

        return $unique;
    }

    /**
     * @param  list<TrainingCandidate>  $candidates
     */
    private function fit(array $candidates, int $budget): TrainingPlan
    {
        $selected = [];
        $omitted = [];
        $used = 0;
        foreach ($candidates as $candidate) {
            $size = $candidate->document->characters();
            if ($used + $size <= $budget) {
                $selected[] = $candidate->document;
                $used += $size;

                continue;
            }
            if ($candidate->isMandatory()) {
                throw TrainingException::budgetExceeded($budget);
            }
            $omitted[] = $candidate->document->id;
        }

        return new TrainingPlan($selected, $omitted, $used, $budget);
    }
}
