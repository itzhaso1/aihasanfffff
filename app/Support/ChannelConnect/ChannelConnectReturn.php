<?php

namespace App\Support\ChannelConnect;

use Illuminate\Http\RedirectResponse;

final class ChannelConnectReturn
{
    /**
     * @param  array<string, scalar|null>  $extra
     */
    public static function appUrl(
        string $channel,
        string $status,
        ?string $message = null,
        array $extra = [],
    ): string {
        $query = array_filter(
            [
                'channel' => $channel,
                'status' => $status,
                'message' => $message,
                ...$extra,
            ],
            fn (mixed $value): bool => $value !== null && $value !== '',
        );

        $scheme = trim((string) config('channel_connect.mobile_scheme', 'hasim'));
        if ($scheme === '') {
            $scheme = 'hasim';
        }

        return $scheme.'://channels/oauth?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * @param  array<string, scalar|null>  $extra
     */
    public static function redirect(
        string $channel,
        string $status,
        ?string $message = null,
        array $extra = [],
    ): RedirectResponse {
        return redirect()->away(self::appUrl($channel, $status, $message, $extra));
    }
}
