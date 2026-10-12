<?php

namespace App\Services\AI\Inbox;

use RuntimeException;

class InboxAgentException extends RuntimeException
{
    public static function timeout(int $timeoutSeconds): self
    {
        return new self("Inbox agent exceeded timeout of {$timeoutSeconds} seconds.");
    }

    public static function maxIterations(int $maxIterations): self
    {
        return new self("Inbox agent exceeded max iterations ({$maxIterations}).");
    }

    public static function malformedResult(string $reason): self
    {
        return new self('Inbox agent produced a malformed result: '.$reason);
    }

    public static function maxToolRounds(int $maxToolRounds): self
    {
        return new self("Inbox agent exceeded max tool rounds ({$maxToolRounds}).");
    }
}
