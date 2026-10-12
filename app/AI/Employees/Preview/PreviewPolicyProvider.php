<?php

declare(strict_types=1);

namespace App\AI\Employees\Preview;

use App\AI\Core\AiRequest;
use App\AI\Core\Contracts\PolicyProvider;
use App\AI\Core\Data\ResolvedEmployee;

/**
 * Temporary: no workspace policy layer exists yet (later phase). Preview runs use the
 * constitution alone as their policy.
 */
final class PreviewPolicyProvider implements PolicyProvider
{
    public function sections(AiRequest $request, ResolvedEmployee $employee): array
    {
        return [];
    }
}
