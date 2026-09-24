<?php

namespace Tests\Unit\Inbox;

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
            ['بدي موظف'],
            ['أريد التحدث مع موظف'],
            ['اريد التحدث مع انسان'],
            ['human agent please'],
            ['talk to a real person'],
        ];
    }

    public function test_does_not_detect_unrelated_messages(): void
    {
        $this->assertFalse(app(InboxHumanHandoffDetector::class)->requestsHumanAgent('ما هي ساعات العمل؟'));
    }
}
