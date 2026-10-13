<?php

declare(strict_types=1);

namespace Tests\Unit\AI\Inbox;

use App\Services\AI\Inbox\InboxHonestReplyFallback;
use Tests\TestCase;

class InboxHonestReplyFallbackTest extends TestCase
{
    public function test_services_question_asks_what_the_customer_needs(): void
    {
        $fallback = app(InboxHonestReplyFallback::class);

        $this->assertSame(
            InboxHonestReplyFallback::OFFERINGS,
            $fallback->forInbound('شو الخدمات المتوفرة عندكم؟'),
        );
        $this->assertSame(
            InboxHonestReplyFallback::GREETING,
            $fallback->forInbound('مرحبا'),
        );
        $this->assertStringNotContainsString('ريال', $fallback->forInbound('شو المنتجات المتوفرة؟'));
        $this->assertStringNotContainsString('تنظيف', $fallback->forInbound('شو الخدمات المتوفرة عندكم؟'));
    }
}
