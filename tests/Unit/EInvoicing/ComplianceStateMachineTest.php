<?php

namespace Tests\Unit\EInvoicing;

use App\EInvoicing\ComplianceStateMachine;
use App\Enums\EInvoicing\ComplianceStatus;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class ComplianceStateMachineTest extends TestCase
{
    public function test_ready_can_move_to_generated_but_not_submitted(): void
    {
        $this->assertTrue(ComplianceStateMachine::canTransition(
            ComplianceStatus::Ready,
            ComplianceStatus::Generated,
        ));
        $this->assertFalse(ComplianceStateMachine::canTransition(
            ComplianceStatus::Ready,
            ComplianceStatus::Submitted,
        ));
    }

    public function test_cleared_and_reported_and_not_applicable_are_terminal(): void
    {
        foreach ([
            ComplianceStatus::Cleared,
            ComplianceStatus::Reported,
            ComplianceStatus::NotApplicable,
        ] as $terminal) {
            $this->assertSame([], ComplianceStateMachine::allowedTargets($terminal));
        }
    }

    public function test_invalid_transition_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid electronic-invoice compliance transition');
        ComplianceStateMachine::assertCanTransition(
            ComplianceStatus::Ready,
            ComplianceStatus::Cleared,
        );
    }
}
