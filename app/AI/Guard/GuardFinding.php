<?php

declare(strict_types=1);

namespace App\AI\Guard;

use App\AI\Core\Decision\GuardResult;
use App\AI\Core\Decision\GuardVerdict;

final readonly class GuardFinding
{
    public function __construct(
        public string $name,
        public GuardVerdict $verdict,
        public ?string $reasonCode = null,
        public ?string $text = null,
    ) {
        if ($verdict !== GuardVerdict::Rejected && $text !== null && trim($text) === '') {
            throw new \InvalidArgumentException('A non-rejected finding cannot carry empty text.');
        }
    }

    public static function pass(string $name, string $text): self
    {
        return new self($name, GuardVerdict::Pass, null, $text);
    }

    public static function repaired(string $name, string $text, string $reasonCode): self
    {
        return new self($name, GuardVerdict::Repaired, $reasonCode, $text);
    }

    public static function rejected(string $name, string $reasonCode): self
    {
        return new self($name, GuardVerdict::Rejected, $reasonCode);
    }

    public function toResult(): GuardResult
    {
        return new GuardResult($this->name, $this->verdict, $this->reasonCode);
    }
}
