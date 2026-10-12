<?php

declare(strict_types=1);

namespace App\AI\Core\Data;

use App\AI\Core\Decision\GuardResult;
use App\AI\Core\Decision\GuardVerdict;
use App\AI\Core\Support\TypedList;
use InvalidArgumentException;

final readonly class GuardOutcome
{
    /** @var list<GuardResult> */
    public array $results;

    /**
     * @param  ?string  $text  Final text for pass/repaired verdicts; ignored when rejected.
     * @param  list<GuardResult>  $results
     */
    public function __construct(
        public GuardVerdict $verdict,
        public ?string $text,
        array $results = [],
    ) {
        if ($verdict !== GuardVerdict::Rejected && trim((string) $text) === '') {
            throw new InvalidArgumentException('An accepted guard outcome must carry text.');
        }
        $this->results = TypedList::of($results, GuardResult::class, 'Guard results');
    }
}
