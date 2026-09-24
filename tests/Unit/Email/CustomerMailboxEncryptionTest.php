<?php

namespace Tests\Unit\Email;

use App\Support\Email\CustomerMailboxEncryption;
use PHPUnit\Framework\TestCase;

class CustomerMailboxEncryptionTest extends TestCase
{
    public function test_smtp_port_defaults_are_not_provider_specific(): void
    {
        $this->assertSame('ssl', CustomerMailboxEncryption::resolveSmtp(null, 465));
        $this->assertSame('tls', CustomerMailboxEncryption::resolveSmtp(null, 587));
        $this->assertSame('none', CustomerMailboxEncryption::resolveSmtp(null, 25));
        $this->assertSame('tls', CustomerMailboxEncryption::resolveSmtp('tls', 465));
        $this->assertSame('none', CustomerMailboxEncryption::resolveSmtp('none', 587));
    }

    public function test_imap_supports_ssl_tls_and_none(): void
    {
        $this->assertSame('ssl', CustomerMailboxEncryption::resolveImap(null, 993));
        $this->assertSame('tls', CustomerMailboxEncryption::resolveImap(null, 143));
        $this->assertSame('none', CustomerMailboxEncryption::resolveImap(null, 110));
        $this->assertSame('none', CustomerMailboxEncryption::resolveImap('none', 993));
        $this->assertSame('tls', CustomerMailboxEncryption::resolveImap('tls', 993));
    }
}
