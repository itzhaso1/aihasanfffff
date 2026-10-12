<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\Worker\IndependentWorkerWebBoundary;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Default-deny web allowlist for independent workers.
 *
 * Runs before route-model binding so forbidden pages do not load data.
 */
final class RestrictIndependentWorkerWeb
{
    public function __construct(
        private readonly IndependentWorkerWebBoundary $boundary,
    ) {}

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->boundary->isIndependentWorker($request->user())) {
            return $next($request);
        }

        if ($this->boundary->allows($request->route()?->getName())) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'هذه الصفحة غير متاحة لحساب العامل على الويب.',
            ], Response::HTTP_FORBIDDEN);
        }

        return redirect()->route('worker.inbox.home');
    }
}
