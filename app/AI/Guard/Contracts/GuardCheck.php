<?php

declare(strict_types=1);

namespace App\AI\Guard\Contracts;

use App\AI\Core\Data\GuardInput;
use App\AI\Guard\GuardFinding;

interface GuardCheck
{
    public function name(): string;

    /** Inspect the current candidate text. Must not perform I/O or mutation. */
    public function inspect(GuardInput $input, string $text): GuardFinding;
}
