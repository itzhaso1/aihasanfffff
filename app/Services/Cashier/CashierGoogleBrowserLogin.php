<?php

namespace App\Services\Cashier;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use RuntimeException;
use Throwable;

class CashierGoogleBrowserLogin
{
    public const TTL_SECONDS = 600;

    public const STATE_PREFIX = 'cashier.';

    public static function cacheKey(string $ticket): string
    {
        return 'cashier.google.ticket.'.$ticket;
    }

    public function configured(): bool
    {
        return trim((string) config('services.google.client_id')) !== ''
            && trim((string) config('services.google.client_secret')) !== '';
    }

    /**
     * Extract the cashier ticket from Google's OAuth `state`.
     *
     * Website Socialite uses a 40-char random string, not a UUID, so a UUID
     * (optionally prefixed) is always the desktop cashier browser flow.
     */
    public function parseTicket(string $state): ?string
    {
        $state = trim($state);
        if ($state === '') {
            return null;
        }

        if (str_starts_with($state, self::STATE_PREFIX)) {
            $state = substr($state, strlen(self::STATE_PREFIX));
        }

        return $this->isTicket($state) ? $state : null;
    }

    public function isCashierTicket(string $state): bool
    {
        return $this->parseTicket($state) !== null;
    }

    /**
     * @return array{ticket: string, auth_url: string, expires_in: int}
     */
    public function start(string $product = 'cashier'): array
    {
        $product = in_array($product, ['cashier', 'finance'], true) ? $product : 'cashier';
        if (! $this->configured()) {
            throw new RuntimeException('يحتاج إعداد Google في ملف .env على Laravel (GOOGLE_CLIENT_ID و GOOGLE_CLIENT_SECRET).');
        }

        $ticket = (string) Str::uuid();
        $this->cache()->put(self::cacheKey($ticket), [
            'status' => 'pending',
            'access_token' => null,
            'error' => null,
            'product' => $product,
        ], self::TTL_SECONDS);

        $redirect = trim((string) config('services.google.redirect'));
        if ($redirect === '') {
            config(['services.google.redirect' => url('/auth/google/callback')]);
        }

        $authUrl = Socialite::driver('google')
            ->stateless()
            ->scopes(['openid', 'profile', 'email'])
            ->with([
                'state' => self::STATE_PREFIX.$ticket,
                'prompt' => 'select_account',
            ])
            ->redirect()
            ->getTargetUrl();

        return [
            'ticket' => $ticket,
            'auth_url' => $authUrl,
            'expires_in' => self::TTL_SECONDS,
        ];
    }

    public function complete(string $provider, string $ticket): Response
    {
        try {
            $socialUser = Socialite::driver($provider)->stateless()->user();
            $token = trim((string) ($socialUser->token ?? ''));
            if ($token === '') {
                $this->mark($ticket, 'failed', error: 'لم يرجع Google رمز الدخول.');

                return $this->htmlPage('فشل تسجيل الدخول عبر Google.', false, $this->productLabel($ticket));
            }
            $this->mark($ticket, 'ready', accessToken: $token);

            return $this->htmlPage(
                'تم تسجيل الدخول عبر Google. أغلق هذه النافذة وعد إلى '.$this->productLabel($ticket).'.',
                true,
                $this->productLabel($ticket),
            );
        } catch (Throwable) {
            $this->mark($ticket, 'failed', error: 'فشل تسجيل الدخول عبر Google.');

            return $this->htmlPage('فشل تسجيل الدخول عبر Google.', false, $this->productLabel($ticket));
        }
    }

    /**
     * @return array{status: string, access_token: ?string, error: ?string}
     */
    public function status(string $ticket): array
    {
        $payload = $this->cache()->get(self::cacheKey($ticket));
        if (! is_array($payload)) {
            return [
                'status' => 'expired',
                'access_token' => null,
                'error' => 'انتهت جلسة Google. أعد المحاولة.',
            ];
        }

        $status = (string) ($payload['status'] ?? 'pending');
        $token = isset($payload['access_token']) ? trim((string) $payload['access_token']) : '';
        $error = isset($payload['error']) ? (string) $payload['error'] : null;

        if ($status === 'ready' && $token !== '') {
            $this->cache()->forget(self::cacheKey($ticket));

            return [
                'status' => 'ready',
                'access_token' => $token,
                'error' => null,
            ];
        }

        return [
            'status' => $status,
            'access_token' => null,
            'error' => $error,
        ];
    }

    private function isTicket(string $value): bool
    {
        return (bool) preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $value
        );
    }

    private function cache(): Repository
    {
        $default = (string) config('cache.default');
        if (! app()->environment('testing') && in_array($default, ['array', 'null', ''], true)) {
            return Cache::store('file');
        }

        return Cache::store();
    }

    private function mark(string $ticket, string $status, ?string $accessToken = null, ?string $error = null): void
    {
        $existing = $this->cache()->get(self::cacheKey($ticket));
        $product = is_array($existing) ? (string) ($existing['product'] ?? 'cashier') : 'cashier';
        $this->cache()->put(self::cacheKey($ticket), [
            'status' => $status,
            'access_token' => $accessToken,
            'error' => $error,
            'product' => $product,
        ], self::TTL_SECONDS);
    }

    private function productLabel(string $ticket): string
    {
        $payload = $this->cache()->get(self::cacheKey($ticket));
        $product = is_array($payload) ? (string) ($payload['product'] ?? 'cashier') : 'cashier';

        return match ($product) {
            'finance' => 'حاسم للمالية',
            default => 'كاشير حاسم',
        };
    }

    private function htmlPage(string $message, bool $ok, string $title = 'كاشير حاسم'): Response
    {
        $color = $ok ? '#049E86' : '#DC2626';
        $html = '<!DOCTYPE html><html lang="ar" dir="rtl"><head><meta charset="utf-8"><title>'.e($title).'</title></head>'
            .'<body style="font-family:sans-serif;padding:40px;text-align:center;color:#0F172A">'
            .'<h1 style="color:'.$color.'">حاسم</h1>'
            .'<p>'.e($message).'</p>'
            .'</body></html>';

        return response($html, 200, ['Content-Type' => 'text/html; charset=utf-8']);
    }
}
