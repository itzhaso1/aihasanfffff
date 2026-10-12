<?php

declare(strict_types=1);

namespace App\AI\Core\Contracts;

use App\AI\Core\AiRequest;
use App\AI\Core\Data\LoadedConstitution;

interface ConstitutionProvider
{
    /** Returns constitution-layer sections and their version. Read-only. */
    public function load(AiRequest $request): LoadedConstitution;
}
