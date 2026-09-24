<?php

namespace App\Services\Auth;

use App\Models\DevicePushToken;
use App\Models\User;
use App\Services\Audit\AuditLogService;

class UserSanctumTokenRevocationService
{
    public function __construct(
        private readonly AuditLogService $auditLogService,
    ) {}

    /**
     * Password reset: invalidate every Sanctum token for the user.
     */
    public function revokeAllAfterReset(User $user): int
    {
        return $this->revokeTokens($user, exceptTokenId: null, action: 'auth.password_reset.tokens_revoked');
    }

    /**
     * Password change: invalidate other sessions/devices while optionally keeping the current token.
     */
    public function revokeOthersAfterChange(User $user, ?int $exceptTokenId = null): int
    {
        return $this->revokeTokens($user, $exceptTokenId, action: 'auth.password_change.tokens_revoked');
    }

    private function revokeTokens(User $user, ?int $exceptTokenId, string $action): int
    {
        $tokenIds = $user->tokens()
            ->when($exceptTokenId, fn ($query) => $query->where('id', '!=', $exceptTokenId))
            ->pluck('id');

        if ($tokenIds->isNotEmpty()) {
            DevicePushToken::query()
                ->where('user_id', $user->id)
                ->whereIn('personal_access_token_id', $tokenIds)
                ->update(['revoked_at' => now()]);
        }

        $count = (int) $user->tokens()
            ->when($exceptTokenId, fn ($query) => $query->where('id', '!=', $exceptTokenId))
            ->delete();

        if ($count > 0) {
            $this->auditLogService->log(
                action: $action,
                entityType: 'user',
                entityId: $user->id,
                actor: $user,
                meta: [
                    'count' => $count,
                    'except_token_id' => $exceptTokenId,
                ],
            );
        }

        return $count;
    }
}
