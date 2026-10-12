<?php

namespace App\Http\Middleware;

use App\Support\Observability\CorrelationContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AssignCorrelationId
{
    public function handle(Request $request, Closure $next): Response
    {
        $header = (string) config('observability.correlation_header', 'X-Correlation-Id');
        $incoming = $request->header($header) ?: $request->header('X-Request-Id');
        CorrelationContext::set(is_string($incoming) ? $incoming : null);

        $response = $next($request);

        $response->headers->set($header, CorrelationContext::id());

        CorrelationContext::reset();

        return $response;
    }
}
