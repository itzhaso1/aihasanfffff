<?php

namespace App\Services\Mobile;

use App\Models\DevicePushToken;
use App\Models\NotificationPreference;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use InvalidArgumentException;

class PushDeviceService
{
    /** @var array<int, string> */
    private const PROVIDERS = ['fcm', 'apns'];

    /** @var array<int, string> */
    private const CATEGORIES = ['messages', 'email'];

    public function register(
        User $user,
        string $token,
        string $provider,
        string $platform,
        ?string $deviceName = null,
        ?int $workspaceId = null,
        ?int $personalAccessTokenId = null,
    ): DevicePushToken {
        $token = trim($token);
        if ($token === '') {
            throw new InvalidArgumentException('رمز الجهاز غير صالح.');
        }

        if (! in_array($provider, self::PROVIDERS, true)) {
            throw new InvalidArgumentException('مزود الإشعارات غير مدعوم.');
        }

        if ($workspaceId === null || $workspaceId <= 0) {
            throw new InvalidArgumentException('مساحة العمل مطلوبة لتسجيل الجهاز.');
        }

        return DevicePushToken::query()->updateOrCreate(
            [
                'user_id' => $user->id,
                'token' => $token,
                'workspace_id' => $workspaceId,
            ],
            [
                'provider' => $provider,
                'platform' => $platform,
                'device_name' => $deviceName,
                'personal_access_token_id' => $personalAccessTokenId,
                'last_seen_at' => now(),
                'revoked_at' => null,
            ],
        );
    }

    public function revoke(User $user, int $id, ?int $workspaceId = null): void
    {
        $record = DevicePushToken::query()
            ->where('user_id', $user->id)
            ->when($workspaceId !== null, fn ($query) => $query->where('workspace_id', $workspaceId))
            ->whereKey($id)
            ->first();

        if (! $record) {
            throw new ModelNotFoundException('رمز الإشعار غير موجود.');
        }

        $record->update(['revoked_at' => now()]);
    }

    public function revokeByToken(User $user, string $token, ?int $workspaceId = null): void
    {
        if ($workspaceId === null || $workspaceId <= 0) {
            throw new InvalidArgumentException('مساحة العمل مطلوبة لإلغاء رمز الجهاز.');
        }

        DevicePushToken::query()
            ->where('user_id', $user->id)
            ->where('token', trim($token))
            ->where('workspace_id', $workspaceId)
            ->update(['revoked_at' => now()]);
    }

    public function markInvalid(DevicePushToken $record): void
    {
        if ($record->revoked_at !== null) {
            return;
        }

        $record->update(['revoked_at' => now()]);
    }

    /**
     * @return Collection<int, DevicePushToken>
     */
    public function activeTokensForUser(User $user, ?int $workspaceId = null): Collection
    {
        return DevicePushToken::query()
            ->where('user_id', $user->id)
            ->whereNull('revoked_at')
            ->when($workspaceId !== null, function ($query) use ($workspaceId): void {
                $query->where('workspace_id', $workspaceId);
            })
            ->get();
    }

    public function shouldNotify(User $user, ?int $workspaceId, string $category): bool
    {
        if (! in_array($category, self::CATEGORIES, true)) {
            return true;
        }

        $preferences = $this->preferences($user, $workspaceId);

        return match ($category) {
            'messages' => (bool) $preferences->messages,
            'email' => (bool) $preferences->email,
            default => true,
        };
    }

    public function preferences(User $user, ?int $workspaceId = null): NotificationPreference
    {
        return NotificationPreference::query()->firstOrCreate(
            [
                'user_id' => $user->id,
                'workspace_id' => $workspaceId,
            ],
            [
                'messages' => true,
                'email' => true,
                'marketing' => false,
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updatePreferences(User $user, array $data, ?int $workspaceId = null): NotificationPreference
    {
        $preferences = $this->preferences($user, $workspaceId);

        $fill = [];
        foreach (['messages', 'email', 'marketing'] as $key) {
            if (array_key_exists($key, $data)) {
                $fill[$key] = (bool) $data[$key];
            }
        }

        if ($fill !== []) {
            $preferences->fill($fill)->save();
        }

        return $preferences->refresh();
    }
}
