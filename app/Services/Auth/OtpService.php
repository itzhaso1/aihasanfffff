<?php

namespace App\Services\Auth;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class OtpService
{
    private const OTP_TTL_SECONDS = 300;

    public function request(string $phone): string
    {
        $throttleKey = $this->throttleKey($phone);
        $hits = (int) Cache::get($throttleKey, 0);
        if ($hits >= (int) config('security.otp.max_requests_per_minute', 5)) {
            throw new \RuntimeException('Too many OTP requests. Try again later.');
        }

        Cache::put($throttleKey, $hits + 1, 60);

        $otp = (string) random_int(100000, 999999);

        Cache::put(
            $this->cacheKey($phone),
            [
                'hash' => Hash::make($otp),
                'attempts' => 0,
            ],
            self::OTP_TTL_SECONDS
        );

        return $otp;
    }

    public function verify(string $phone, string $otp): bool
    {
        $data = Cache::get($this->cacheKey($phone));

        if (! is_array($data) || ! isset($data['hash'])) {
            return false;
        }

        $attempts = (int) ($data['attempts'] ?? 0);
        if ($attempts >= (int) config('security.otp.max_verify_attempts', 5)) {
            Cache::forget($this->cacheKey($phone));

            return false;
        }

        $data['attempts'] = $attempts + 1;
        Cache::put($this->cacheKey($phone), $data, self::OTP_TTL_SECONDS);

        if (! Hash::check($otp, $data['hash'])) {
            return false;
        }

        Cache::forget($this->cacheKey($phone));

        return true;
    }

    private function cacheKey(string $phone): string
    {
        return 'auth:otp:'.Str::lower($phone);
    }

    private function throttleKey(string $phone): string
    {
        return 'auth:otp:throttle:'.Str::lower($phone);
    }
}
