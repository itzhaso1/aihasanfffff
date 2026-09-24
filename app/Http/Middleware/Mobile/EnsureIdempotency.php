<?php

namespace App\Http\Middleware\Mobile;

use App\Services\Mobile\IdempotencyService;
use App\Support\Tenancy\WorkspaceContext;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class EnsureIdempotency
{
    public function __construct(
        private readonly IdempotencyService $idempotencyService,
        private readonly WorkspaceContext $workspaceContext,
    ) {}

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! in_array($request->method(), ['POST', 'PUT', 'PATCH'], true)) {
            return $next($request);
        }

        $user = $request->user();
        $workspace = $this->workspaceContext->workspace();

        if (! $user || $workspace === null) {
            return $next($request);
        }

        $claim = $this->idempotencyService->begin($user, $workspace, $request);

        if ($claim->isSkipped()) {
            return $next($request);
        }

        if ($claim->isReplay() && $claim->record) {
            return $this->idempotencyService->replay($claim->record);
        }

        if ($claim->isConflict()) {
            return response()->json([
                'success' => false,
                'message' => 'Idempotency-Key already used with a different request.',
            ], 409);
        }

        if ($claim->isBusy()) {
            return response()->json([
                'success' => false,
                'message' => 'This request is already in progress.',
            ], 409);
        }

        try {
            /** @var Response $response */
            $response = $next($request);
        } catch (Throwable $exception) {
            if ($claim->isClaimed() && $claim->record) {
                $this->idempotencyService->release($claim->record);
            }

            throw $exception;
        }

        if ($claim->isClaimed() && $claim->record && $response instanceof JsonResponse) {
            $this->idempotencyService->complete($claim->record, $response);
        }

        return $response;
    }
}
