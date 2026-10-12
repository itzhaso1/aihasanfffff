<?php

namespace App\Services\Commerce\Intelligence;

use App\Enums\Commerce\CommerceEvaluationStatus;

final readonly class CommerceRecommendationEvaluation
{
    /**
     * @param  array<int, string>  $missingRequirements
     * @param  array<int, string>  $incompatibilityReasons
     * @param  array<int, string>  $warnings
     * @param  array<string, mixed>  $evaluatedAttributes
     * @param  array<string, mixed>  $provenanceSummary
     */
    public function __construct(
        public int $itemId,
        public ?int $variantId,
        public CommerceEvaluationStatus $status,
        public array $missingRequirements,
        public array $incompatibilityReasons,
        public array $warnings,
        public array $evaluatedAttributes,
        public array $provenanceSummary,
    ) {}

    public function isSuitable(): bool
    {
        return $this->status === CommerceEvaluationStatus::Compatible
            || $this->status === CommerceEvaluationStatus::ConditionallySuitable;
    }

    public function toArray(): array
    {
        return [
            'item_id' => $this->itemId,
            'variant_id' => $this->variantId,
            'status' => $this->status->value,
            'is_suitable' => $this->isSuitable(),
            'missing_requirements' => $this->missingRequirements,
            'incompatibility_reasons' => $this->incompatibilityReasons,
            'warnings' => $this->warnings,
            'evaluated_attributes' => $this->evaluatedAttributes,
            'provenance_summary' => $this->provenanceSummary,
        ];
    }
}
