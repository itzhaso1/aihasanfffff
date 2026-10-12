<?php

namespace App\Services\Meta;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;

class MetaTokenVault
{
    public function encrypt(?string $plaintext): ?string
    {
        $value = trim((string) $plaintext);
        if ($value === '') {
            return null;
        }

        return Crypt::encryptString($value);
    }

    public function decrypt(?string $ciphertext): ?string
    {
        $payload = trim((string) $ciphertext);
        if ($payload === '') {
            return null;
        }

        try {
            return Crypt::decryptString($payload);
        } catch (DecryptException) {
            return null;
        }
    }
}
