<?php

namespace App\Services\Mobile;

use App\Models\ApiIdempotencyKey;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class IdempotencyService
{
    public const PROCESSING_STATUS = 102;

    public function find(User $user, string $key, string $route, int $workspaceId): ?ApiIdempotencyKey
    {
        return ApiIdempotencyKey::query()
            ->where('user_id', $user->id)
            ->where('workspace_id', $workspaceId)
            ->where('key', $key)
            ->where('route', $route)
            ->where('expires_at', '>', now())
            ->first();
    }

    /**
     * Atomically claim a key before work starts so concurrent retries cannot both execute.
     */
    public function begin(User $user, Workspace $workspace, Request $request, int $ttlHours = 24, int $processingTimeoutSeconds = 90): IdempotencyBeginResult
    {
        $key = $this->keyFromRequest($request);
        if ($key === null) {
            return IdempotencyBeginResult::skipped();
        }

        $hash = $this->requestHash($request);
        $route = $request->path();
        $timeout = max(15, $processingTimeoutSeconds);

        try {
            $record = ApiIdempotencyKey::query()->create([
                'user_id' => $user->id,
                'workspace_id' => $workspace->id,
                'key' => $key,
                'route' => $route,
                'request_hash' => $hash,
                'status_code' => self::PROCESSING_STATUS,
                'response_body' => ['status' => 'processing'],
                'expires_at' => now()->addHours($ttlHours),
            ]);

            return IdempotencyBeginResult::claimed($record);
        } catch (UniqueConstraintViolationException) {
            return $this->resolveExistingClaim($user, $workspace, $key, $route, $hash, $timeout, $ttlHours);
        }
    }

    public function complete(ApiIdempotencyKey $record, JsonResponse $response): void
    {
        $status = $response->getStatusCode();
        if ($status >= 500 || $status === 409) {
            $this->release($record);

            return;
        }

        $content = json_decode($response->getContent() ?: 'null', true);
        if (! is_array($content)) {
            $this->release($record);

            return;
        }

        $record->status_code = $status;
        $record->response_body = $content;
        $record->expires_at = now()->addHours(24);
        $record->save();
    }

    public function release(?ApiIdempotencyKey $record): void
    {
        if ($record === null) {
            return;
        }

        ApiIdempotencyKey::query()->whereKey($record->id)->delete();
    }

    /**
     * @param  array<string, mixed>  $body
     */
    public function store(
        User $user,
        string $key,
        string $route,
        int $statusCode,
        array $body,
        Workspace $workspace,
        ?string $requestHash = null,
        int $ttlHours = 24,
    ): ApiIdempotencyKey {
        return ApiIdempotencyKey::query()->updateOrCreate(
            [
                'user_id' => $user->id,
                'workspace_id' => $workspace->id,
                'key' => $key,
                'route' => $route,
            ],
            [
                'request_hash' => $requestHash,
                'status_code' => $statusCode,
                'response_body' => $body,
                'expires_at' => now()->addHours($ttlHours),
            ]
        );
    }

    public function conflicts(ApiIdempotencyKey $record, string $requestHash): bool
    {
        $stored = trim((string) ($record->request_hash ?? ''));
        if ($stored === '') {
            return false;
        }

        return ! hash_equals($stored, $requestHash);
    }

    public function requestHash(Request $request): string
    {
        return hash('sha256', $request->method()."\n".$request->path()."\n".$request->getContent());
    }

    public function replay(ApiIdempotencyKey $record): JsonResponse
    {
        return response()->json($record->response_body, $record->status_code);
    }

    public function keyFromRequest(Request $request): ?string
    {
        $key = trim((string) (
            $request->header('Idempotency-Key')
            ?? $request->input('idempotency_key', '')
        ));

        return $key !== '' ? $key : null;
    }

    public function isProcessing(ApiIdempotencyKey $record): bool
    {
        return (int) $record->status_code === self::PROCESSING_STATUS
            || (int) $record->status_code < 200;
    }

    private function resolveExistingClaim(
        User $user,
        Workspace $workspace,
        string $key,
        string $route,
        string $hash,
        int $timeout,
        int $ttlHours,
    ): IdempotencyBeginResult {
        return DB::transaction(function () use ($user, $workspace, $key, $route, $hash, $timeout, $ttlHours): IdempotencyBeginResult {
            $existing = ApiIdempotencyKey::query()
                ->where('user_id', $user->id)
                ->where('workspace_id', $workspace->id)
                ->where('key', $key)
                ->where('route', $route)
                ->lockForUpdate()
                ->first();

            if ($existing === null) {
                $created = ApiIdempotencyKey::query()->create([
                    'user_id' => $user->id,
                    'workspace_id' => $workspace->id,
                    'key' => $key,
                    'route' => $route,
                    'request_hash' => $hash,
                    'status_code' => self::PROCESSING_STATUS,
                    'response_body' => ['status' => 'processing'],
                    'expires_at' => now()->addHours($ttlHours),
                ]);

                return IdempotencyBeginResult::claimed($created);
            }

            $expired = $existing->expires_at !== null && $existing->expires_at->lte(now());
            $stale = $this->isProcessing($existing)
                && $existing->updated_at !== null
                && $existing->updated_at->lte(now()->subSeconds($timeout));

            if ($this->isProcessing($existing) && ! $stale && ! $expired) {
                return IdempotencyBeginResult::busy($existing);
            }

            if (! $this->isProcessing($existing) && ! $expired) {
                if ($this->conflicts($existing, $hash)) {
                    return IdempotencyBeginResult::conflict($existing);
                }

                return IdempotencyBeginResult::replay($existing);
            }

            $existing->request_hash = $hash;
            $existing->status_code = self::PROCESSING_STATUS;
            $existing->response_body = ['status' => 'processing'];
            $existing->expires_at = now()->addHours($ttlHours);
            $existing->save();

            return IdempotencyBeginResult::claimed($existing);
        });
    }
}
