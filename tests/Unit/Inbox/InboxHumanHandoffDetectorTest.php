<?php

namespace Tests\Unit\Inbox;

use App\Enums\ConversationHandoffReason;
use App\Services\Inbox\InboxHumanHandoffDetector;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class InboxHumanHandoffDetectorTest extends TestCase
{
    #[DataProvider('positivePhrasesProvider')]
    public function test_detects_human_handoff_phrases(string $phrase): void
    {
        $this->assertTrue(app(InboxHumanHandoffDetector::class)->requestsHumanAgent($phrase));
    }

    public static function positivePhrasesProvider(): array
    {
        return [
            ['حولني لشخص'],
            ['حولني على الفريق'],
            ['حَوِّلني لو سمحت على الفريق'],
            ['بدي موظف'],
            ['أريد التحدث مع موظف'],
            ['اريد التحدث مع انسان'],
            ['human agent please'],
            ['talk to a real person'],
        ];
    }

    public function test_does_not_detect_unrelated_messages(): void
    {
        $detector = app(InboxHumanHandoffDetector::class);

        $this->assertFalse($detector->requestsHumanAgent('ما هي ساعات العمل؟'));
        $this->assertFalse($detector->requestsHumanAgent('حولني على المنتج'));
        $this->assertNull($detector->staffDecisionReason('كم الكمية المتوفرة من الدفتر؟'));
        $this->assertSame(
            ConversationHandoffReason::RefundRequest,
            $detector->staffDecisionReason('أبي أسترجع المبلغ'),
        );
    }
}
