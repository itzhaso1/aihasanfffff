<?php

namespace App\Services\Mobile;

use App\Models\DevicePushToken;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Audit\AuditLogService;
use App\Services\Auth\AuthenticationService;
use App\Services\Auth\SocialAuthService;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\NewAccessToken;
use Laravel\Sanctum\PersonalAccessToken;
use RuntimeException;

class MobileAuthService
{
    public function __construct(
        private readonly AuthenticationService $authenticationService,
        private readonly AuditLogService $auditLogService,
        private readonly SocialAuthService $socialAuthService,
    ) {}

    /**
     * @param  array{device_name?:string,device_type?:string,user_agent?:string,ip_address?:string}  $device
     * @return array{user:User,workspace:Workspace|null,workspaces:Collection,token:NewAccessToken}
     */
    public function loginWithPassword(string $emailOrPhone, string $password, ?int $workspaceId, array $device = []): array
    {
        $user = User::query()
            ->where('email', $emailOrPhone)
            ->orWhere('phone', $emailOrPhone)
            ->first();

        if (! $user || ! Hash::check($password, $user->password)) {
            throw new AuthenticationException('بيانات الدخول غير صحيحة.');
        }

        $workspaces = $user->workspaces()
            ->wherePivot('status', 'active')
            ->get();

        if ($workspaces->isEmpty()) {
            throw new ModelNotFoundException('لا توجد مساحة عمل مرتبطة بهذا الحساب.');
        }

        $workspace = $workspaceId
            ? $workspaces->firstWhere('id', $workspaceId)
            : $workspaces->first();

        if (! $workspace) {
            throw new ModelNotFoundException('مساحة العمل غير متاحة لهذا المستخدم.');
        }

        $token = $this->issueDeviceToken($user, $workspace->id, $device);

        $this->auditLogService->log(
            action: 'mobile.auth.login',
            entityType: 'user',
            entityId: $user->id,
            actor: $user,
            meta: [
                'device_name' => $device['device_name'] ?? null,
                'device_type' => $device['device_type'] ?? null,
            ],
            workspaceId: $workspace->id,
        );

        return [
            'user' => $user,
            'workspace' => $workspace,
            'workspaces' => $workspaces,
            'token' => $token,
        ];
    }

    /**
     * Social login via provider access token, returning the same envelope as password login.
     *
     * @param  array{device_name?:string,device_type?:string,user_agent?:string,ip_address?:string}  $device
     * @return array{user:User,workspace:Workspace|null,workspaces:Collection,token:NewAccessToken}
     */
    public function loginWithSocial(string $provider, string $accessToken, ?int $workspaceId, array $device = []): array
    {
        if (! in_array($provider, ['google', 'facebook'], true)) {
            throw new RuntimeException('مزود تسجيل الدخول الاجتماعي غير مدعوم.');
        }

        try {
            $result = $this->socialAuthService->loginWithAccessToken($provider, $accessToken, $workspaceId);
        } catch (RuntimeException $exception) {
            throw new AuthenticationException($exception->getMessage() ?: 'فشل تسجيل الدخول الاجتماعي.');
        }

        /** @var User $user */
        $user = $result['user'];
        /** @var Workspace $workspace */
        $workspace = $result['workspace'];

        // Replace the generic API token from SocialAuthService with a mobile device token.
        if (isset($result['token']) && $result['token'] instanceof NewAccessToken) {
            $result['token']->accessToken->delete();
        }

        $workspaces = $user->workspaces()
            ->wherePivot('status', 'active')
            ->get();

        $token = $this->issueDeviceToken($user, $workspace->id, $device);

        $this->auditLogService->log(
            action: 'mobile.auth.social_login',
            entityType: 'user',
            entityId: $user->id,
            actor: $user,
            meta: [
                'provider' => $provider,
                'device_name' => $device['device_name'] ?? null,
                'device_type' => $device['device_type'] ?? null,
            ],
            workspaceId: $workspace->id,
        );

        return [
            'user' => $user,
            'workspace' => $workspace,
            'workspaces' => $workspaces,
            'token' => $token,
        ];
    }

    /**
     * @param  array{device_name?:string,device_type?:string,user_agent?:string,ip_address?:string}  $device
     */
    public function logoutCurrent(User $user, mixed $token): void
    {
        $tokenId = null;
        if ($token instanceof PersonalAccessToken) {
            $tokenId = (int) $token->id;
        } elseif (is_object($token) && isset($token->id)) {
            $tokenId = (int) $token->id;
        }

        if ($tokenId) {
            DevicePushToken::query()
                ->where('user_id', $user->id)
                ->where('personal_access_token_id', $tokenId)
                ->update(['revoked_at' => now()]);

            $user->tokens()->whereKey($tokenId)->delete();
        }

        $this->auditLogService->log(
            action: 'mobile.auth.logout',
            entityType: 'user',
            entityId: $user->id,
            actor: $user,
        );
    }

    public function switchWorkspace(User $user, mixed $token, int $workspaceId, array $device = []): Workspace
    {
        $workspace = $user->workspaces()
            ->where('workspaces.id', $workspaceId)
            ->wherePivot('status', 'active')
            ->first();

        if (! $workspace) {
            throw new ModelNotFoundException('مساحة العمل غير متاحة لهذا المستخدم.');
        }

        if ($token instanceof PersonalAccessToken) {
            $token->forceFill(['workspace_id' => $workspace->id])->save();

            if (Schema::hasColumn('personal_access_tokens', 'device_name') && $device !== []) {
                $token->forceFill(array_filter([
                    'device_name' => $device['device_name'] ?? $token->device_name ?? null,
                    'device_type' => $device['device_type'] ?? $token->device_type ?? null,
                    'user_agent' => $device['user_agent'] ?? $token->user_agent ?? null,
                    'ip_address' => $device['ip_address'] ?? $token->ip_address ?? null,
                ], fn ($v) => $v !== null))->save();
            }
        }

        $this->auditLogService->log(
            action: 'mobile.workspace.switched',
            entityType: 'workspace',
            entityId: $workspace->id,
            actor: $user,
            workspaceId: $workspace->id,
        );

        return $workspace;
    }

    /**
     * @param  array{device_name?:string,device_type?:string,user_agent?:string,ip_address?:string}  $device
     */
    public function issueDeviceToken(User $user, int $workspaceId, array $device = []): NewAccessToken
    {
        $name = trim((string) ($device['device_name'] ?? 'hasim-mobile'));
        if ($name === '') {
            $name = 'hasim-mobile';
        }

        $token = $user->createToken(
            name: $name,
            abilities: ['mobile', '*'],
            expiresAt: now()->addDays((int) config('security.mobile_token_days', 60)),
        );

        $fill = ['workspace_id' => $workspaceId];
        if (Schema::hasColumn('personal_access_tokens', 'device_name')) {
            $fill['device_name'] = $device['device_name'] ?? $name;
            $fill['device_type'] = $device['device_type'] ?? 'mobile';
            $fill['user_agent'] = $device['user_agent'] ?? null;
            $fill['ip_address'] = $device['ip_address'] ?? null;
        }

        $token->accessToken->forceFill($fill)->save();

        return $token;
    }

    public function revokeSession(User $user, int $tokenId): void
    {
        $token = $user->tokens()->whereKey($tokenId)->firstOrFail();

        DevicePushToken::query()
            ->where('user_id', $user->id)
            ->where('personal_access_token_id', $token->id)
            ->update(['revoked_at' => now()]);

        $token->delete();

        $this->auditLogService->log(
            action: 'mobile.session.revoked',
            entityType: 'personal_access_token',
            entityId: $tokenId,
            actor: $user,
            meta: ['token_id' => $tokenId],
        );
    }

    public function revokeAllSessions(User $user, ?int $exceptTokenId = null): int
    {
        $query = $user->tokens();
        if ($exceptTokenId) {
            $query->where('id', '!=', $exceptTokenId);
        }

        $ids = $query->pluck('id');
        DevicePushToken::query()
            ->where('user_id', $user->id)
            ->whereIn('personal_access_token_id', $ids)
            ->update(['revoked_at' => now()]);

        $count = $user->tokens()
            ->when($exceptTokenId, fn ($q) => $q->where('id', '!=', $exceptTokenId))
            ->delete();

        $this->auditLogService->log(
            action: 'mobile.session.revoked_all',
            entityType: 'user',
            entityId: $user->id,
            actor: $user,
            meta: ['count' => $count],
        );

        return (int) $count;
    }
}
