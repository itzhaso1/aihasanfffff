<?php

namespace App\Services\Finance;

use App\Models\Finance\FinanceInvoice;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class InvoicePdfAccessService
{
    public const QUERY_TOKEN = 'download_token';

    public const QUERY_EXPIRES = 'expires';

    public const TTL_MINUTES = 120;

    /**
     * @return array{token: string, expires: int, file_name: string}
     */
    public function issue(FinanceInvoice $invoice, User $user): array
    {
        $expires = now()->addMinutes(self::TTL_MINUTES)->getTimestamp();

        return [
            'token' => $this->token($invoice, $user, $expires),
            'expires' => $expires,
            'file_name' => $this->downloadFileName($invoice),
        ];
    }

    /**
     * @return array{download_token: string, expires: int}
     */
    public function query(FinanceInvoice $invoice, User $user): array
    {
        $issued = $this->issue($invoice, $user);

        return [
            self::QUERY_TOKEN => $issued['token'],
            self::QUERY_EXPIRES => $issued['expires'],
        ];
    }

    public function webUrl(FinanceInvoice $invoice, ?User $user = null): string
    {
        $user ??= Auth::user();
        abort_unless($user instanceof User, 401);

        return route('workspace.finance.invoices.pdf', array_merge(
            ['invoice' => $invoice],
            $this->query($invoice, $user),
        ));
    }

    public function assertValid(Request $request, FinanceInvoice $invoice, User $user): void
    {
        $token = (string) $request->query(self::QUERY_TOKEN, '');
        $expires = (int) $request->query(self::QUERY_EXPIRES, 0);

        if ($token === '' || $expires <= 0 || $expires < now()->getTimestamp()) {
            abort(404);
        }

        if (! hash_equals($this->token($invoice, $user, $expires), $token)) {
            abort(404);
        }
    }

    public function downloadFileName(FinanceInvoice $invoice): string
    {
        $digest = hash_hmac(
            'sha256',
            'invoice-pdf-name|'.$invoice->id.'|'.$invoice->workspace_id,
            $this->secret(),
        );

        return 'invoice-'.substr($digest, 0, 20).'.pdf';
    }

    private function token(FinanceInvoice $invoice, User $user, int $expires): string
    {
        $payload = implode('|', [
            'invoice-pdf',
            (int) $invoice->id,
            (int) $invoice->workspace_id,
            (int) $user->id,
            $expires,
        ]);

        return hash_hmac('sha256', $payload, $this->secret());
    }

    private function secret(): string
    {
        $key = (string) config('app.key');
        if (str_starts_with($key, 'base64:')) {
            $decoded = base64_decode(substr($key, 7), true);
            if (is_string($decoded) && $decoded !== '') {
                return $decoded;
            }
        }

        return $key;
    }
}
