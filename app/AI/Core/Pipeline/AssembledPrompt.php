<?php

declare(strict_types=1);

namespace App\AI\Core\Pipeline;

use App\AI\Core\AiDirective;
use App\AI\Core\Data\ContextPayload;
use App\AI\Core\Decision\TrainingVersion;
use App\AI\Core\Prompt\PromptComposition;
use App\AI\Core\Support\TypedList;
use LogicException;

final readonly class AssembledPrompt
{
    /** @var list<TrainingVersion> */
    public array $trainingVersions;

    /**
     * @param  list<TrainingVersion>  $trainingVersions
     */
    private function __construct(
        public ?PromptComposition $composition,
        public ?string $constitutionVersion,
        array $trainingVersions,
        public ContextPayload $context,
    ) {
        $this->trainingVersions = TypedList::of($trainingVersions, TrainingVersion::class, 'Training versions');
    }

    /**
     * @param  list<TrainingVersion>  $trainingVersions
     */
    public static function composed(
        PromptComposition $composition,
        ?string $constitutionVersion,
        array $trainingVersions,
        ContextPayload $context,
    ): self {
        return new self($composition, $constitutionVersion, $trainingVersions, $context);
    }

    /** The context layer decided to skip or hand off before any prompt was needed. */
    public static function directed(?string $constitutionVersion, ContextPayload $context): self
    {
        if ($context->directive === AiDirective::Respond) {
            throw new LogicException('A directed prompt requires a skip or hand-off directive.');
        }

        return new self(null, $constitutionVersion, [], $context);
    }

    public function directive(): AiDirective
    {
        return $this->context->directive;
    }
}
