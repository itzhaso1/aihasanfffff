<?php

namespace Tests\Support\Email;

use App\Models\EmailAccount;
use App\Services\Email\CustomerSmtpTransportFactory;
use Illuminate\Mail\Transport\ArrayTransport;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Throwable;

class RecordingCustomerSmtpTransportFactory extends CustomerSmtpTransportFactory
{
    /**
     * @var list<array<string, mixed>>
     */
    public array $built = [];

    public ?Throwable $nextError = null;

    public ?ArrayTransport $lastTransport = null;

    public function make(EmailAccount $account): TransportInterface
    {
        $this->built[] = $this->connectionSettings($account);

        if ($this->nextError instanceof Throwable) {
            $error = $this->nextError;
            $this->nextError = null;

            throw $error;
        }

        return $this->lastTransport = new ArrayTransport;
    }
}
