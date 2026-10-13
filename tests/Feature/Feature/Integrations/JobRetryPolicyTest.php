<?php

namespace Tests\Feature\Feature\Integrations;

use App\Jobs\Email\SendCampaignRecipientJob;
use App\Jobs\ProcessAIResponse;
use App\Jobs\ProcessPaymentWebhook;
use App\Jobs\Push\SendPushNotificationJob;
use App\Jobs\SendWhatsAppMessage;
use Tests\TestCase;

class JobRetryPolicyTest extends TestCase
{
    public function test_critical_jobs_define_retry_and_backoff(): void
    {
        $ai = new ProcessAIResponse(1, 2, 3);
        $this->assertSame(3, $ai->tries);
        $this->assertSame(120, $ai->timeout);
        $this->assertSame([15, 60, 180], $ai->backoff);

        $campaign = new SendCampaignRecipientJob(1, 1);
        $this->assertSame(2, $campaign->tries);
        $this->assertSame(120, $campaign->timeout);

        $payment = new ProcessPaymentWebhook('local', [], []);
        $this->assertSame(5, $payment->tries);

        $push = new SendPushNotificationJob(1, 't', 'b', [], 'messages', 1);
        $this->assertSame(3, $push->tries);
        $this->assertSame([30, 120, 300], $push->backoff);

        $wa = new SendWhatsAppMessage('pn', '966', 'hi', 1, 1);
        $this->assertSame(3, $wa->tries);
        $this->assertSame([30, 120, 300], $wa->backoff);
    }
}
