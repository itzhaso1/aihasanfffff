<?php

declare(strict_types=1);

namespace App\AI\Core;

use App\AI\Core\Decision\AiFailure;
use App\AI\Core\Decision\AiReference;
use App\AI\Core\Decision\ConfirmedAction;
use App\AI\Core\Decision\FactRecord;
use App\AI\Core\Decision\GuardResult;
use App\AI\Core\Decision\ModelUsage;
use App\AI\Core\Decision\SafeData;
use App\AI\Core\Decision\ToolCallRecord;
use App\AI\Core\Decision\TrainingVersion;
use App\AI\Core\Support\TypedList;
use InvalidArgumentException;
use JsonSerializable;

/**
 * Serializable audit trace of one run. It holds identifiers, versions and safe
 * summaries only: no prompt text, no raw tool results, no credentials.
 */
final readonly class AiDecision implements JsonSerializable
{
    public const SCHEMA_VERSION = 1;

    /** @var list<TrainingVersion> */
    public array $trainingVersions;

    /** @var list<AiReference> */
    public array $resolvedReferences;

    /** @var list<ToolCallRecord> */
    public array $toolCalls;

    /** @var list<FactRecord> */
    public array $facts;

    /** @var list<ConfirmedAction> */
    public array $confirmedActions;

    /** @var list<GuardResult> */
    public array $guardResults;

    /** @var array<array-key, mixed> */
    public array $metadata;

    /**
     * @param  list<TrainingVersion>  $trainingVersions
     * @param  list<AiReference>  $resolvedReferences
     * @param  list<ToolCallRecord>  $toolCalls
     * @param  list<FactRecord>  $facts
     * @param  list<ConfirmedAction>  $confirmedActions
     * @param  list<GuardResult>  $guardResults
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public AiResponseStatus $status,
        public AiRunMode $runMode,
        public ?int $employeeId = null,
        public ?string $employeeConfigVersion = null,
        public ?string $constitutionVersion = null,
        array $trainingVersions = [],
        public ?string $intent = null,
        array $resolvedReferences = [],
        array $toolCalls = [],
        array $facts = [],
        array $confirmedActions = [],
        array $guardResults = [],
        public ?ModelUsage $modelUsage = null,
        public ?AiFailure $failure = null,
        array $metadata = [],
    ) {
        if (($status === AiResponseStatus::Failed) !== ($failure !== null)) {
            throw new InvalidArgumentException('A failure is required for failed decisions and forbidden otherwise.');
        }

        $this->trainingVersions = TypedList::of($trainingVersions, TrainingVersion::class, 'Training versions');
        $this->resolvedReferences = TypedList::of($resolvedReferences, AiReference::class, 'Resolved references');
        $this->toolCalls = TypedList::of($toolCalls, ToolCallRecord::class, 'Tool calls');
        $this->facts = TypedList::of($facts, FactRecord::class, 'Facts');
        $this->confirmedActions = TypedList::of($confirmedActions, ConfirmedAction::class, 'Confirmed actions');
        $this->guardResults = TypedList::of($guardResults, GuardResult::class, 'Guard results');
        $this->metadata = SafeData::sanitize($metadata);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $this->status->value,
            'run_mode' => $this->runMode->value,
            'employee_id' => $this->employeeId,
            'employee_config_version' => $this->employeeConfigVersion,
            'constitution_version' => $this->constitutionVersion,
            'training_versions' => self::map($this->trainingVersions),
            'intent' => $this->intent,
            'resolved_references' => self::map($this->resolvedReferences),
            'tool_calls' => self::map($this->toolCalls),
            'facts' => self::map($this->facts),
            'confirmed_actions' => self::map($this->confirmedActions),
            'guard_results' => self::map($this->guardResults),
            'model_usage' => $this->modelUsage?->toArray(),
            'failure' => $this->failure?->toArray(),
            'metadata' => $this->metadata,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /**
     * @param  list<object>  $items
     * @return list<array<string, mixed>>
     */
    private static function map(array $items): array
    {
        return array_map(static fn (object $item): array => $item->toArray(), $items);
    }
}
