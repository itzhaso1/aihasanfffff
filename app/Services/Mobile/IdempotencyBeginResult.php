<?php

namespace App\Services\Mobile;

use App\Models\ApiIdempotencyKey;

final class IdempotencyBeginResult
{
    public const SKIPPED = 'skipped';

    public const CLAIMED = 'claimed';

    public const REPLAY = 'replay';

    public const CONFLICT = 'conflict';

    public const BUSY = 'busy';

    public function __construct(
        public readonly string $outcome,
        public readonly ?ApiIdempotencyKey $record = null,
    ) {}

    public static function skipped(): self
    {
        return new self(self::SKIPPED);
    }

    public static function claimed(ApiIdempotencyKey $record): self
    {
        return new self(self::CLAIMED, $record);
    }

    public static function replay(ApiIdempotencyKey $record): self
    {
        return new self(self::REPLAY, $record);
    }

    public static function conflict(?ApiIdempotencyKey $record = null): self
    {
        return new self(self::CONFLICT, $record);
    }

    public static function busy(ApiIdempotencyKey $record): self
    {
        return new self(self::BUSY, $record);
    }

    public function isSkipped(): bool
    {
        return $this->outcome === self::SKIPPED;
    }

    public function isClaimed(): bool
    {
        return $this->outcome === self::CLAIMED;
    }

    public function isReplay(): bool
    {
        return $this->outcome === self::REPLAY;
    }

    public function isConflict(): bool
    {
        return $this->outcome === self::CONFLICT;
    }

    public function isBusy(): bool
    {
        return $this->outcome === self::BUSY;
    }
}
