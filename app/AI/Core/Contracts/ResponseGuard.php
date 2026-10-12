<?php

declare(strict_types=1);

namespace App\AI\Core\Contracts;

use App\AI\Core\Data\GuardInput;
use App\AI\Core\Data\GuardOutcome;

interface ResponseGuard
{
    /** Passes, repairs or rejects a candidate reply. Must be free of side effects. */
    public function review(GuardInput $input): GuardOutcome;
}
