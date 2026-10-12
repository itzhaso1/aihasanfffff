<?php

declare(strict_types=1);

namespace Tests\Unit\AI\Core;

use App\AI\Core\AiDecision;
use App\AI\Core\AiFailureCode;
use App\AI\Core\AiResponse;
use App\AI\Core\AiResponseStatus;
use App\AI\Core\AiRunMode;
use App\AI\Core\AiRuntimeStage;
use App\AI\Core\Decision\AiFailure;
use LogicException;
use PHPUnit\Framework\TestCase;

class AiResponseTest extends TestCase
{
    public function test_replied_in_live_mode_is_the_only_deliverable_result(): void
    {
        $live = AiResponse::replied('سعره 299 ريال', $this->decision(AiResponseStatus::Replied, AiRunMode::Live));
        $preview = AiResponse::replied('سعره 299 ريال', $this->decision(AiResponseStatus::Replied, AiRunMode::Preview));
        $evaluation = AiResponse::replied('سعره 299 ريال', $this->decision(AiResponseStatus::Replied, AiRunMode::Evaluation));

        $this->assertSame(AiResponseStatus::Replied, $live->status);
        $this->assertSame('سعره 299 ريال', $live->text);
        $this->assertTrue($live->isDeliverable());
        $this->assertFalse($preview->isDeliverable());
        $this->assertFalse($evaluation->isDeliverable());
    }

    public function test_skipped_has_no_text_and_is_not_deliverable(): void
    {
        $response = AiResponse::skipped($this->decision(AiResponseStatus::Skipped));

        $this->assertNull($response->text);
        $this->assertFalse($response->isDeliverable());
    }

    public function test_handed_off_is_never_deliverable_even_with_a_staff_draft(): void
    {
        $withoutDraft = AiResponse::handedOff($this->decision(AiResponseStatus::HandedOff));
        $withDraft = AiResponse::handedOff($this->decision(AiResponseStatus::HandedOff), 'draft for staff');

        $this->assertNull($withoutDraft->text);
        $this->assertFalse($withoutDraft->isDeliverable());
        $this->assertFalse($withDraft->isDeliverable());
        $this->assertSame([], $withDraft->decision->confirmedActions);
    }

    public function test_failed_has_no_text_and_no_fallback(): void
    {
        $decision = new AiDecision(
            status: AiResponseStatus::Failed,
            runMode: AiRunMode::Live,
            failure: new AiFailure(AiRuntimeStage::CallModel, AiFailureCode::ModelError),
        );

        $response = AiResponse::failed($decision);

        $this->assertNull($response->text);
        $this->assertSame(AiResponseStatus::Failed, $response->status);
        $this->assertFalse($response->isDeliverable());
    }

    public function test_replied_requires_text(): void
    {
        $this->expectException(LogicException::class);

        AiResponse::replied('  ', $this->decision(AiResponseStatus::Replied));
    }

    public function test_status_must_match_decision(): void
    {
        $this->expectException(LogicException::class);

        AiResponse::skipped($this->decision(AiResponseStatus::Replied));
    }

    public function test_status_values_are_stable(): void
    {
        $this->assertSame(
            ['replied', 'skipped', 'handed_off', 'failed'],
            array_map(static fn (AiResponseStatus $s): string => $s->value, AiResponseStatus::cases()),
        );
    }

    private function decision(AiResponseStatus $status, AiRunMode $mode = AiRunMode::Live): AiDecision
    {
        return new AiDecision(status: $status, runMode: $mode, employeeId: 7);
    }
}
