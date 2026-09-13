<?php

namespace App\Services\Pos;

use App\Models\PosDevice;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class PosDeviceRegistry
{
    /**
     * Idempotent device registration bound to the authenticated workspace.
     * The same device_id cannot be claimed by another workspace.
     */
    public function register(
        Workspace $workspace,
        User $user,
        string $deviceId,
        ?string $name = null,
        ?string $platform = null,
    ): PosDevice {
        $deviceId = trim($deviceId);
        if ($deviceId === '' || strlen($deviceId) > 64) {
            throw ValidationException::withMessages([
                'device_id' => ['معرّف الجهاز غير صالح.'],
            ]);
        }

        return DB::transaction(function () use ($workspace, $user, $deviceId, $name, $platform) {
            $existing = PosDevice::withoutGlobalScopes()
                ->where('device_id', $deviceId)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                if ((int) $existing->workspace_id !== (int) $workspace->id) {
                    throw new HttpException(
                        403,
                        'هذا الجهاز مسجّل لمساحة عمل أخرى ولا يمكن إعادة استخدامه.'
                    );
                }

                $existing->fill([
                    'user_id' => $user->id,
                    'name' => $name !== null && trim($name) !== '' ? trim($name) : $existing->name,
                    'platform' => $platform !== null && trim($platform) !== ''
                        ? trim($platform)
                        : $existing->platform,
                    'last_seen_at' => now(),
                ]);
                $existing->save();

                return $existing->fresh();
            }

            return PosDevice::withoutGlobalScopes()->create([
                'device_id' => $deviceId,
                'workspace_id' => $workspace->id,
                'user_id' => $user->id,
                'name' => $name !== null && trim($name) !== '' ? trim($name) : 'كاشير حاسم',
                'platform' => $platform !== null && trim($platform) !== '' ? trim($platform) : 'cashier',
                'registered_at' => now(),
                'last_seen_at' => now(),
            ]);
        });
    }

    /**
     * A POS may only push/pull after it is registered to this workspace.
     */
    public function requireRegistered(Workspace $workspace, string $deviceId): PosDevice
    {
        $deviceId = trim($deviceId);
        if ($deviceId === '') {
            throw new HttpException(422, 'معرّف الجهاز مطلوب.');
        }

        $device = PosDevice::withoutGlobalScopes()
            ->where('device_id', $deviceId)
            ->first();

        if (! $device) {
            throw new HttpException(403, 'الجهاز غير مسجّل. سجّل الجهاز أولاً عبر /devices/register.');
        }

        if ((int) $device->workspace_id !== (int) $workspace->id) {
            throw new HttpException(403, 'هذا الجهاز مسجّل لمساحة عمل أخرى.');
        }

        return $device;
    }

    public function touch(PosDevice $device, ?int $cursor = null, ?string $error = null): void
    {
        $device->fill([
            'last_seen_at' => now(),
            'last_sync_at' => now(),
            'last_error' => $error,
        ]);
        if ($cursor !== null) {
            $device->last_cursor = $cursor;
        }
        $device->save();
    }
}
