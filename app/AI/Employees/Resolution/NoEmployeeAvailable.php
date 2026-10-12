<?php

declare(strict_types=1);

namespace App\AI\Employees\Resolution;

use RuntimeException;

/** No usable employee exists for the run; the core reports it as a failed run. */
final class NoEmployeeAvailable extends RuntimeException
{
    public static function forWorkspace(): self
    {
        return new self('No active AI employee is available for this workspace.');
    }
}
