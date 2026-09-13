<?php

namespace App\Services\Mobile;

use App\Models\ApiIdempotencyKey;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class IdempotencyService
{
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
        $key = trim((string) $request->header('Idempotency-Key', ''));

        return $key !== '' ? $key : null;
    }
}
