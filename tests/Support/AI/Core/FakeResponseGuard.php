<?php

declare(strict_types=1);

namespace Tests\Support\AI\Core;

use App\AI\Core\Contracts\ResponseGuard;
use App\AI\Core\Data\GuardInput;
use App\AI\Core\Data\GuardOutcome;
use App\AI\Core\Decision\GuardResult;
use App\AI\Core\Decision\GuardVerdict;
use RuntimeException;

final class FakeResponseGuard implements ResponseGuard
{
    public GuardVerdict $verdict = GuardVerdict::Pass;

    public ?string $repairedText = null;

    public bool $throw = false;

    /** @var list<GuardInput> */
    public array $inputs = [];

    public function review(GuardInput $input): GuardOutcome
    {
        $this->inputs[] = $input;
        if ($this->throw) {
            throw new RuntimeException('guard exploded with Bearer abc.def.ghi');
        }

        return new GuardOutcome(
            $this->verdict,
            $this->verdict === GuardVerdict::Repaired ? $this->repairedText : $input->text,
            [new GuardResult('fake_guard', $this->verdict, $this->verdict === GuardVerdict::Pass ? null : 'test_reason')],
        );
    }
}
