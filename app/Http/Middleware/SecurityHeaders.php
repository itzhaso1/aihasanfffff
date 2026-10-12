<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        return $this->apply($request, $response);
    }

    public function apply(Request $request, Response $response): Response
    {
        if (! config('security-headers.enabled', true)) {
            return $response;
        }

        foreach (config('security-headers.headers', []) as $name => $value) {
            if (! is_string($name) || $value === null || $value === '') {
                continue;
            }

            if (! $response->headers->has($name)) {
                $response->headers->set($name, (string) $value);
            }
        }

        if ($this->shouldSendHsts($request)) {
            $response->headers->set('Strict-Transport-Security', $this->hstsDirective());
        }

        return $response;
    }

    private function shouldSendHsts(Request $request): bool
    {
        if (! config('security-headers.hsts.enabled')) {
            return false;
        }

        return $request->isSecure();
    }

    private function hstsDirective(): string
    {
        $maxAge = max(0, (int) config('security-headers.hsts.max_age', 31536000));

        return 'max-age='.$maxAge;
    }
}
