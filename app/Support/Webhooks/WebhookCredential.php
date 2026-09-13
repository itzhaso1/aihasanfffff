<?php

namespace App\Support\Webhooks;

final class WebhookCredential
{
    /**
     * Return a usable webhook secret/token, or null when missing/blank.
     * Whitespace-only values are treated as unconfigured.
     */
    public static function configured(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
