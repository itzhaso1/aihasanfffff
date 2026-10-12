<?php

namespace App\Services\Email;

use App\Models\EmailAccount;
use App\Support\Email\CustomerMailboxEncryption;
use App\Support\Network\MailboxHostValidator;
use Symfony\Component\Mailer\Transport\Dsn;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransportFactory;
use Symfony\Component\Mailer\Transport\Smtp\Stream\SocketStream;
use Symfony\Component\Mailer\Transport\TransportInterface;

class CustomerSmtpTransportFactory
{
    public const TIMEOUT_SECONDS = 15;

    public function make(EmailAccount $account): TransportInterface
    {
        MailboxHostValidator::assertAllowed((string) $account->smtp_host, 'smtp_host');

        $settings = $this->connectionSettings($account);
        $encryption = $settings['encryption'];
        $scheme = $encryption === CustomerMailboxEncryption::SSL ? 'smtps' : 'smtp';

        $transport = (new EsmtpTransportFactory)->create(new Dsn(
            $scheme,
            $settings['host'],
            $settings['username'],
            $account->mailboxPassword(),
            $settings['port'],
            [
                'auto_tls' => $encryption === CustomerMailboxEncryption::TLS,
                'require_tls' => $encryption === CustomerMailboxEncryption::TLS,
                'timeout' => self::TIMEOUT_SECONDS,
            ],
        ));

        if ($transport instanceof EsmtpTransport) {
            $stream = $transport->getStream();
            if ($stream instanceof SocketStream) {
                $stream->setTimeout(self::TIMEOUT_SECONDS);
            }
        }

        return $transport;
    }

    /**
     * SMTP settings used to build a transport. Never includes the password.
     *
     * @return array{host: string, port: int, username: string, encryption: string, timeout: int, email_account_id: int|null, workspace_id: int|null}
     */
    public function connectionSettings(EmailAccount $account): array
    {
        return [
            'host' => (string) $account->smtp_host,
            'port' => (int) $account->smtp_port,
            'username' => $account->smtpUsername(),
            'encryption' => $account->resolvedSmtpEncryption(),
            'timeout' => self::TIMEOUT_SECONDS,
            'email_account_id' => $account->id ? (int) $account->id : null,
            'workspace_id' => $account->workspace_id ? (int) $account->workspace_id : null,
        ];
    }
}
