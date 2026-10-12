<?php

namespace Tests\Unit\Email;

use App\Services\Email\CustomerMailboxErrorClassifier;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class CustomerMailboxErrorClassifierTest extends TestCase
{
    public function test_classifies_auth_timeout_and_connection_errors(): void
    {
        $classifier = new CustomerMailboxErrorClassifier;

        $this->assertSame(CustomerMailboxErrorClassifier::AUTH, $classifier->classify(new RuntimeException('535 Authentication failed')));
        $this->assertSame(CustomerMailboxErrorClassifier::TIMEOUT, $classifier->classify(new RuntimeException('Connection timed out')));
        $this->assertSame(CustomerMailboxErrorClassifier::CONNECTION_REFUSED, $classifier->classify(new RuntimeException('Connection refused')));
        $this->assertSame(CustomerMailboxErrorClassifier::TLS, $classifier->classify(new RuntimeException('SSL certificate problem')));
        $this->assertSame(CustomerMailboxErrorClassifier::PROVIDER_REJECTED, $classifier->classify(new RuntimeException('550 relay rejected')));
        $this->assertSame(CustomerMailboxErrorClassifier::CONFIGURATION, $classifier->classify(new RuntimeException('IMAP extension missing')));
    }

    public function test_redacts_secrets_from_text(): void
    {
        $classifier = new CustomerMailboxErrorClassifier;
        $redacted = $classifier->redactSecrets('smtp://user:super-secret@host:587 password=super-secret', 'super-secret');

        $this->assertStringNotContainsString('super-secret', $redacted);
        $this->assertStringContainsString('[redacted]', $redacted);
    }

    public function test_user_messages_are_safe(): void
    {
        $classifier = new CustomerMailboxErrorClassifier;

        $this->assertSame('تعذر إرسال الرسالة. تحقق من إعدادات SMTP.', $classifier->userMessage('send'));
        $this->assertSame('تعذر الاتصال بخادم SMTP. تحقق من اسم الخادم والمنفذ وبيانات الدخول.', $classifier->userMessage('test'));
    }
}
