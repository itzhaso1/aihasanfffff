<?php

declare(strict_types=1);

namespace App\AI\Training;

use App\AI\Core\AiRequest;
use App\AI\Core\Contracts\TrainingProvider;
use App\AI\Core\Data\ContextPayload;
use App\AI\Core\Data\ResolvedEmployee;
use App\AI\Core\Data\TrainingSelection;

/**
 * Core training provider backed by the Markdown training library.
 */
final class LibraryTrainingProvider implements TrainingProvider
{
    public function __construct(
        private readonly TrainingContextBuilder $builder = new TrainingContextBuilder,
        private readonly TrainingQueryFactory $queries = new DefaultTrainingQueryFactory,
        private readonly GreetingStyleSelector $socialStyle = new GreetingStyleSelector,
    ) {}

    public function select(AiRequest $request, ResolvedEmployee $employee, ContextPayload $context): TrainingSelection
    {
        $selection = $this->builder->build($this->queries->make($request, $employee, $context));
        $references = $this->socialStyle->referenceSection($request->message->text);
        if ($references === null) {
            return $selection;
        }

        return new TrainingSelection(
            sections: [...$selection->sections, $references],
            versions: $selection->versions,
        );
    }
}
