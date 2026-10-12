<?php

namespace App\Support\Webhooks;

use Illuminate\Http\Request;

final class WebhookRequest
{
    /**
     * @return array<string, string>
     */
    public static function flattenHeaders(Request $request): array
    {
        return collect($request->headers->all())
            ->map(fn ($value) => is_array($value) ? (string) ($value[0] ?? '') : (string) $value)
            ->all();
    }
}
