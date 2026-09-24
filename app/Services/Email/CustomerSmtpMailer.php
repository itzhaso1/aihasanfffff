<?php

namespace App\Services\Email;

use App\Exceptions\CustomerMailboxException;
use App\Mail\WorkspaceBrandedEmail;
use App\Models\EmailAccount;
use App\Models\EmailMessage;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Mail\Mailer;
use Illuminate\Mail\Transport\ArrayTransport;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Throwable;

class CustomerSmtpMailer
{
    public function __construct(
        private readonly CustomerSmtpTransportFactory $transportFactory,
        private readonly CustomerMailboxErrorClassifier $errorClassifier,
        private readonly ViewFactory $views,
        private readonly Dispatcher $events,
    ) {}

    /**
     * @param  list<string>  $recipients
     */
    public function send(EmailAccount $account, EmailMessage $emailMessage, array $recipients): ?string
    {
        try {
            $mailer = $this->makeMailer($account);
            $mailable = (new WorkspaceBrandedEmail($emailMessage, $account))->to($recipients);
            $sentMessage = $mailer->send($mailable);

            return $this->messageId($sentMessage);
        } catch (CustomerMailboxException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw $this->errorClassifier->wrap($exception, 'send', $account, $emailMessage);
        }
    }

    public function testConnection(EmailAccount $account): void
    {
        try {
            $transport = $this->transportFactory->make($account);

            if ($transport instanceof EsmtpTransport) {
                try {
                    $transport->start();
                } finally {
                    $transport->stop();
                }

                return;
            }

            if ($transport instanceof ArrayTransport) {
                return;
            }
        } catch (CustomerMailboxException $exception) {
            throw $exception;
        } catch (TransportExceptionInterface|Throwable $exception) {
            throw $this->errorClassifier->wrap($exception, 'test', $account);
        }
    }

    /**
     * @return array{host: string, port: int, username: string, encryption: string, timeout: int, email_account_id: int|null, workspace_id: int|null}
     */
    public function connectionSettings(EmailAccount $account): array
    {
        return $this->transportFactory->connectionSettings($account);
    }

    private function makeMailer(EmailAccount $account): Mailer
    {
        $transport = $this->transportFactory->make($account);
        $mailer = new Mailer(
            'customer-smtp-'.($account->id ?: 'draft'),
            $this->views,
            $transport,
            $this->events,
        );
        $mailer->alwaysFrom($account->email, $account->fromDisplayName());
        $mailer->alwaysReplyTo($account->email, $account->fromDisplayName());

        return $mailer;
    }

    private function messageId(mixed $sentMessage): ?string
    {
        if ($sentMessage instanceof SentMessage) {
            $id = $sentMessage->getMessageId();

            return $id !== '' ? $id : null;
        }

        return null;
    }
}
