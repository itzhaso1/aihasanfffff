<?php

namespace App\Services\Email;

use App\Exceptions\CustomerMailboxException;
use App\Models\EmailAccount;
use App\Models\EmailMessage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class CustomerMailboxErrorClassifier
{
    public const AUTH = 'auth';

    public const TIMEOUT = 'timeout';

    public const CONNECTION_REFUSED = 'connection_refused';

    public const TLS = 'tls';

    public const PROVIDER_REJECTED = 'provider_rejected';

    public const CONFIGURATION = 'configuration';

    public const UNKNOWN = 'unknown';

    public function classify(Throwable $exception): string
    {
        $haystack = strtolower($exception->getMessage().' '.$exception::class);

        if ($this->contains($haystack, [
            'authentication',
            'authentica',
            '535',
            '534',
            'invalid login',
            'invalid credentials',
            'auth failed',
            'username and password',
            'password rejected',
        ])) {
            return self::AUTH;
        }

        if ($this->contains($haystack, ['timed out', 'timeout', 'time out'])) {
            return self::TIMEOUT;
        }

        if ($this->contains($haystack, ['connection refused', 'could not connect', 'connection reset', 'network is unreachable'])) {
            return self::CONNECTION_REFUSED;
        }

        if ($this->contains($haystack, [
            'ssl',
            'tls',
            'certificate',
            'starttls',
            'unable to connect with tls',
            'stream_socket_enable_crypto',
        ])) {
            return self::TLS;
        }

        if ($this->contains($haystack, [
            '550',
            '551',
            '552',
            '553',
            '554',
            'relay',
            'rejected',
            'blocked',
            'spam',
        ])) {
            return self::PROVIDER_REJECTED;
        }

        if ($this->contains($haystack, [
            'not configured',
            'missing',
            'invalid argument',
            'extension',
            'imap_open',
        ])) {
            return self::CONFIGURATION;
        }

        return self::UNKNOWN;
    }

    public function userMessage(string $context = 'send'): string
    {
        return match ($context) {
            'test' => 'تعذر الاتصال بخادم SMTP. تحقق من اسم الخادم والمنفذ وبيانات الدخول.',
            'imap' => 'تعذر مزامنة الوارد. تحقق من إعدادات IMAP.',
            default => 'تعذر إرسال الرسالة. تحقق من إعدادات SMTP.',
        };
    }

    public function wrap(
        Throwable $exception,
        string $context,
        ?EmailAccount $account = null,
        ?EmailMessage $message = null,
    ): CustomerMailboxException {
        if ($exception instanceof CustomerMailboxException) {
            $this->log($exception, $context);

            return $exception;
        }

        $wrapped = new CustomerMailboxException(
            $this->userMessage($context),
            $this->classify($exception),
            $account?->workspace_id ?? $message?->workspace_id,
            $account?->id ?? $message?->email_account_id,
            $message?->id,
            $exception,
        );

        $this->log($wrapped, $context);

        return $wrapped;
    }

    public function isRetryable(Throwable $exception): bool
    {
        $category = $exception instanceof CustomerMailboxException
            ? $exception->category
            : $this->classify($exception);

        return in_array($category, [
            self::TIMEOUT,
            self::CONNECTION_REFUSED,
            self::UNKNOWN,
        ], true);
    }

    public function safeStoredError(Throwable $exception, string $context = 'send'): string
    {
        if ($exception instanceof CustomerMailboxException) {
            return $exception->category.': '.$exception->getMessage();
        }

        return $this->classify($exception).': '.$this->userMessage($context);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    public function log(CustomerMailboxException $exception, string $context, array $extra = []): void
    {
        Log::warning('customer-mailbox-failed', array_filter([
            'context' => $context,
            'category' => $exception->category,
            'workspace_id' => $exception->workspaceId,
            'email_account_id' => $exception->emailAccountId,
            'email_message_id' => $exception->emailMessageId,
            ...$extra,
        ], fn (mixed $value): bool => $value !== null && $value !== ''));
    }

    public function redactSecrets(string $value, ?string $password = null): string
    {
        $redacted = $value;
        if (is_string($password) && $password !== '') {
            $redacted = str_replace($password, '[redacted]', $redacted);
        }

        $redacted = preg_replace('/(?:password|passwd|pwd|secret|token|api[_-]?key)\s*[:=]\s*\S+/i', '[redacted]', $redacted) ?? $redacted;
        $redacted = preg_replace('#//([^:\s/]+):([^@\s/]+)@#', '//$1:[redacted]@', $redacted) ?? $redacted;

        return Str::limit($redacted, 240);
    }

    /**
     * @param  list<string>  $needles
     */
    private function contains(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }
}
