<?php

namespace Tests\Unit\Network;

use App\Support\Network\MailboxHostValidator;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class MailboxHostValidatorTest extends TestCase
{
    public function test_blocks_loopback_and_private_literal_hosts(): void
    {
        foreach (['127.0.0.1', 'localhost', '10.0.0.1', '192.168.1.10', '::1'] as $host) {
            try {
                MailboxHostValidator::assertAllowed($host);
                $this->fail('Expected blocked host: '.$host);
            } catch (ValidationException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_allows_public_resolvable_host(): void
    {
        MailboxHostValidator::assertAllowed('example.com');

        $this->assertTrue(true);
    }

    public function test_blocks_metadata_hostnames(): void
    {
        $this->expectException(ValidationException::class);
        MailboxHostValidator::assertAllowed('metadata.google.internal');
    }
}
