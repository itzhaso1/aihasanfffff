<?php

namespace App\Http\Controllers\Api\Cashier\V1;

use App\Http\Controllers\Api\Cashier\CashierController;
use App\Http\Controllers\Api\Cashier\Concerns\AuthorizesCashier;
use App\Http\Controllers\Api\Cashier\Concerns\ResolvesCashierWorkspace;
use App\Services\Pos\PosSyncPullService;
use App\Services\Pos\PosSyncPushService;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

class SyncController extends CashierController
{
    use AuthorizesCashier;
    use ResolvesCashierWorkspace;

    public function __construct(
        private readonly WorkspaceContext $workspaceContext,
        private readonly PosSyncPullService $pull,
        private readonly PosSyncPushService $push,
    ) {}

    public function changes(Request $request): JsonResponse
    {
        $workspace = $this->requireWorkspace($this->workspaceContext);
        $this->authorizeCashier($request, $workspace);

        $since = (int) $request->query('since', 0);
        $limit = (int) $request->query('limit', 200);
        if ($since < 0) {
            return $this->fail('قيمة since غير صالحة.', 422);
        }

        // Workspace comes from auth middleware / WorkspaceContext — never trust a client workspace body.
        $payload = $this->pull->changes($workspace, $since, $limit);

        return $this->ok($payload);
    }

    /**
     * Batch push from an offline POS device. Idempotent per operation UUID.
     */
    public function push(Request $request): JsonResponse
    {
        $workspace = $this->requireWorkspace($this->workspaceContext);
        $user = $this->authorizeCashier($request, $workspace);

        $validated = $request->validate([
            'device_id' => ['required', 'string', 'max:64'],
            'operations' => ['required', 'array', 'max:'.PosSyncPushService::MAX_OPERATIONS],
            'operations.*.id' => ['required', 'string', 'min:4', 'max:160'],
            'operations.*.type' => ['required', 'string', 'max:80'],
            'operations.*.created_at' => ['nullable', 'string', 'max:40'],
            'operations.*.data' => ['nullable', 'array'],
        ]);

        $deviceId = $this->resolveDeviceId($request, $validated['device_id']);
        if ($deviceId instanceof JsonResponse) {
            return $deviceId;
        }

        try {
            $result = $this->push->push(
                $workspace,
                $user,
                $deviceId,
                array_values($validated['operations']),
            );
        } catch (HttpException $e) {
            return $this->fail($e->getMessage(), $e->getStatusCode());
        }

        return $this->ok($result);
    }

    /**
     * Incremental pull (cursor / change log). Same payload as GET /sync/changes.
     */
    public function pull(Request $request): JsonResponse
    {
        $workspace = $this->requireWorkspace($this->workspaceContext);
        $this->authorizeCashier($request, $workspace);

        $validated = $request->validate([
            'device_id' => ['required', 'string', 'max:64'],
            'cursor' => ['nullable', 'integer', 'min:0'],
            'since' => ['nullable', 'integer', 'min:0'],
            'limit' => ['nullable', 'integer', 'min:0', 'max:500'],
        ]);

        $deviceId = $this->resolveDeviceId($request, $validated['device_id']);
        if ($deviceId instanceof JsonResponse) {
            return $deviceId;
        }

        $cursor = (int) ($validated['cursor'] ?? $validated['since'] ?? 0);
        $limit = (int) ($validated['limit'] ?? 200);

        try {
            $payload = $this->push->pull($workspace, $deviceId, $cursor, $limit);
        } catch (HttpException $e) {
            return $this->fail($e->getMessage(), $e->getStatusCode());
        }

        return $this->ok($payload);
    }

    private function resolveDeviceId(Request $request, string $bodyDeviceId): string|JsonResponse
    {
        $bodyDeviceId = trim($bodyDeviceId);
        $header = trim((string) $request->header('X-Device-Id', ''));
        if ($header !== '' && $header !== $bodyDeviceId) {
            return $this->fail('X-Device-Id لا يطابق device_id في الطلب.', 422);
        }

        return $bodyDeviceId;
    }
}
