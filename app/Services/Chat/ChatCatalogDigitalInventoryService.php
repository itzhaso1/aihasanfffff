<?php

namespace App\Services\Chat;

use App\Enums\ChatCatalog\ChatCatalogDigitalUnitStatus;
use App\Enums\ChatCatalog\ChatCatalogDigitalUnitType;
use App\Models\ChatCatalogDigitalInventoryPool;
use App\Models\ChatCatalogDigitalInventoryUnit;
use App\Models\ChatCatalogItem;
use App\Models\ChatCatalogItemDigitalProfile;
use App\Models\ChatCatalogItemVariant;
use App\Models\Workspace;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use RuntimeException;

class ChatCatalogDigitalInventoryService
{
    public function createPool(
        Workspace $workspace,
        ChatCatalogItem $item,
        string $name,
        ?ChatCatalogItemVariant $variant = null,
        int $lowStockThreshold = 5,
        int $reservationTimeoutSeconds = 900,
    ): ChatCatalogDigitalInventoryPool {
        $this->assertSameWorkspace($workspace, $item, $variant);
        if ($item->classification?->value !== 'digital') {
            throw new InvalidArgumentException('Only digital catalog items can own a digital inventory pool.');
        }
        $this->ensureDigitalProfile($item, $variant);

        return ChatCatalogDigitalInventoryPool::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'chat_catalog_item_id' => $item->id,
            'chat_catalog_item_variant_id' => $variant?->id,
            'name' => trim($name),
            'low_stock_threshold' => max(0, $lowStockThreshold),
            'reservation_timeout_seconds' => max(60, min(86400, $reservationTimeoutSeconds)),
        ]);
    }

    public function importSecretUnit(
        ChatCatalogDigitalInventoryPool $pool,
        ChatCatalogDigitalUnitType $type,
        string $secret,
        ?\DateTimeInterface $expiresAt = null,
    ): ChatCatalogDigitalInventoryUnit {
        $secret = trim($secret);
        if ($secret === '') {
            throw new InvalidArgumentException('Digital secret cannot be empty.');
        }

        $fingerprint = hash('sha256', $secret);

        try {
            return DB::transaction(function () use ($pool, $type, $secret, $expiresAt, $fingerprint): ChatCatalogDigitalInventoryUnit {
                $duplicate = ChatCatalogDigitalInventoryUnit::withoutGlobalScopes()
                    ->where('workspace_id', $pool->workspace_id)
                    ->where('secret_fingerprint', $fingerprint)
                    ->exists();
                if ($duplicate) {
                    throw new InvalidArgumentException('Duplicate digital unit fingerprint.');
                }

                return ChatCatalogDigitalInventoryUnit::withoutGlobalScopes()->create([
                    'workspace_id' => $pool->workspace_id,
                    'chat_catalog_digital_inventory_pool_id' => $pool->id,
                    'unit_type' => $type,
                    'status' => ChatCatalogDigitalUnitStatus::Available,
                    'encrypted_secret' => $secret,
                    'secret_fingerprint' => $fingerprint,
                    'expires_at' => $expiresAt,
                    'metadata' => [],
                ]);
            });
        } catch (UniqueConstraintViolationException) {
            throw new InvalidArgumentException('Duplicate digital unit fingerprint.');
        }
    }

    /**
     * Stores only a private storage reference. This phase deliberately has no
     * customer delivery endpoint, public URL, or secret-bearing response.
     *
     * @param  array<string, mixed>  $asset
     */
    public function importPrivateFileUnit(
        ChatCatalogDigitalInventoryPool $pool,
        array $asset,
        ?\DateTimeInterface $expiresAt = null,
    ): ChatCatalogDigitalInventoryUnit {
        $disk = (string) ($asset['disk'] ?? 'local');
        $path = trim((string) ($asset['path'] ?? ''));
        $workspacePrefix = 'workspaces/'.$pool->workspace_id.'/chat-catalog/digital/';
        if ($path === ''
            || str_contains($path, '..')
            || ! str_starts_with($path, $workspacePrefix)
            || ! Storage::disk($disk)->exists($path)) {
            throw new InvalidArgumentException('Private digital asset does not exist.');
        }

        return ChatCatalogDigitalInventoryUnit::withoutGlobalScopes()->create([
            'workspace_id' => $pool->workspace_id,
            'chat_catalog_digital_inventory_pool_id' => $pool->id,
            'unit_type' => ChatCatalogDigitalUnitType::File,
            'status' => ChatCatalogDigitalUnitStatus::Available,
            'expires_at' => $expiresAt,
            'metadata' => [
                'disk' => $disk,
                'path' => $path,
                'filename' => $asset['filename'] ?? basename($path),
                'mime_type' => $asset['mime_type'] ?? null,
                'byte_size' => $asset['byte_size'] ?? Storage::disk($disk)->size($path),
                'checksum' => $asset['checksum'] ?? null,
                'version' => $asset['version'] ?? null,
            ],
        ]);
    }

    /**
     * @return array{imported: int, skipped_duplicates: int}
     */
    public function bulkImportSecrets(
        ChatCatalogDigitalInventoryPool $pool,
        ChatCatalogDigitalUnitType $type,
        array $secrets,
        ?\DateTimeInterface $expiresAt = null,
    ): array {
        $imported = 0;
        $skipped = 0;
        foreach ($secrets as $raw) {
            $secret = trim((string) $raw);
            if ($secret === '') {
                continue;
            }
            try {
                $this->importSecretUnit($pool, $type, $secret, $expiresAt);
                $imported++;
            } catch (InvalidArgumentException $exception) {
                if (str_contains($exception->getMessage(), 'Duplicate')) {
                    $skipped++;

                    continue;
                }
                throw $exception;
            }
        }

        return ['imported' => $imported, 'skipped_duplicates' => $skipped];
    }

    public function countAvailableUnits(
        ChatCatalogDigitalInventoryPool $pool,
        ?ChatCatalogDigitalUnitType $unitType = null,
    ): int {
        $this->expireDueUnits($pool);
        $this->releaseExpiredReservations($pool);

        return (int) ChatCatalogDigitalInventoryUnit::withoutGlobalScopes()
            ->where('workspace_id', $pool->workspace_id)
            ->where('chat_catalog_digital_inventory_pool_id', $pool->id)
            ->where('status', ChatCatalogDigitalUnitStatus::Available->value)
            ->when($unitType, fn ($query) => $query->where('unit_type', $unitType->value))
            ->where(function ($query): void {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->count();
    }

    public function reserveUnit(
        ChatCatalogDigitalInventoryPool $pool,
        string $reservationKey,
        ?int $ttlSeconds = null,
        ?ChatCatalogDigitalUnitType $unitType = null,
    ): ChatCatalogDigitalInventoryUnit {
        $reservationKey = trim($reservationKey);
        if ($reservationKey === '') {
            throw new InvalidArgumentException('Reservation key cannot be empty.');
        }
        $this->expireDueUnits($pool);
        $this->releaseExpiredReservations($pool);

        $ttlSeconds ??= $pool->reservation_timeout_seconds ?? 900;
        try {
            return DB::transaction(function () use ($pool, $reservationKey, $ttlSeconds, $unitType): ChatCatalogDigitalInventoryUnit {
                $existing = ChatCatalogDigitalInventoryUnit::withoutGlobalScopes()
                    ->where('workspace_id', $pool->workspace_id)
                    ->where('reservation_key', $reservationKey)
                    ->lockForUpdate()
                    ->first();
                if ($existing) {
                    return $existing;
                }

                $unit = ChatCatalogDigitalInventoryUnit::withoutGlobalScopes()
                    ->where('workspace_id', $pool->workspace_id)
                    ->where('chat_catalog_digital_inventory_pool_id', $pool->id)
                    ->where('status', ChatCatalogDigitalUnitStatus::Available->value)
                    ->when($unitType, fn ($query) => $query->where('unit_type', $unitType->value))
                    ->where(function ($query): void {
                        $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
                    })
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->first();

                if (! $unit) {
                    throw new RuntimeException('No available digital unit.');
                }

                $this->transitionLocked($unit, ChatCatalogDigitalUnitStatus::Reserved);
                $unit->update([
                    'reservation_key' => $reservationKey,
                    'reserved_until' => now()->addSeconds(max(60, $ttlSeconds)),
                ]);

                return $unit->fresh();
            });
        } catch (UniqueConstraintViolationException) {
            $existing = ChatCatalogDigitalInventoryUnit::withoutGlobalScopes()
                ->where('workspace_id', $pool->workspace_id)
                ->where('reservation_key', $reservationKey)
                ->first();
            if ($existing) {
                return $existing;
            }

            throw new RuntimeException('Could not establish digital reservation.');
        }
    }

    public function releaseReservation(ChatCatalogDigitalInventoryUnit $unit, string $reservationKey): ChatCatalogDigitalInventoryUnit
    {
        return DB::transaction(function () use ($unit, $reservationKey): ChatCatalogDigitalInventoryUnit {
            $locked = ChatCatalogDigitalInventoryUnit::withoutGlobalScopes()
                ->where('workspace_id', $unit->workspace_id)
                ->whereKey($unit->id)
                ->lockForUpdate()
                ->firstOrFail();
            if (! hash_equals((string) $locked->reservation_key, $reservationKey)) {
                throw new InvalidArgumentException('Reservation key mismatch.');
            }
            if ($locked->status !== ChatCatalogDigitalUnitStatus::Reserved) {
                throw new InvalidArgumentException('Unit is not reserved.');
            }

            $this->transitionLocked($locked, ChatCatalogDigitalUnitStatus::Available);
            $locked->update(['reservation_key' => null, 'reserved_until' => null]);

            return $locked->fresh();
        });
    }

    public function consumeReserved(ChatCatalogDigitalInventoryUnit $unit, string $reservationKey): ChatCatalogDigitalInventoryUnit
    {
        return DB::transaction(function () use ($unit, $reservationKey): ChatCatalogDigitalInventoryUnit {
            $locked = ChatCatalogDigitalInventoryUnit::withoutGlobalScopes()
                ->where('workspace_id', $unit->workspace_id)
                ->whereKey($unit->id)
                ->lockForUpdate()
                ->firstOrFail();
            if (! hash_equals((string) $locked->reservation_key, $reservationKey)) {
                throw new InvalidArgumentException('Reservation key mismatch.');
            }

            $this->transitionLocked($locked, ChatCatalogDigitalUnitStatus::Consumed);
            $locked->update(['reservation_key' => null, 'reserved_until' => null]);

            return $locked->fresh();
        });
    }

    public function replaceSecret(
        ChatCatalogDigitalInventoryUnit $unit,
        string $secret,
    ): ChatCatalogDigitalInventoryUnit {
        $secret = trim($secret);
        if ($secret === '' || $unit->unit_type === ChatCatalogDigitalUnitType::File) {
            throw new InvalidArgumentException('This digital unit cannot accept a replacement secret.');
        }
        $fingerprint = hash('sha256', $secret);

        try {
            return DB::transaction(function () use ($unit, $secret, $fingerprint): ChatCatalogDigitalInventoryUnit {
                $locked = ChatCatalogDigitalInventoryUnit::withoutGlobalScopes()
                    ->where('workspace_id', $unit->workspace_id)
                    ->whereKey($unit->id)
                    ->lockForUpdate()
                    ->firstOrFail();
                if ($locked->status !== ChatCatalogDigitalUnitStatus::Available) {
                    throw new InvalidArgumentException('Only available units can have their secret replaced.');
                }
                $duplicate = ChatCatalogDigitalInventoryUnit::withoutGlobalScopes()
                    ->where('workspace_id', $locked->workspace_id)
                    ->where('secret_fingerprint', $fingerprint)
                    ->where('id', '!=', $locked->id)
                    ->exists();
                if ($duplicate) {
                    throw new InvalidArgumentException('Duplicate digital unit fingerprint.');
                }
                $locked->update([
                    'encrypted_secret' => $secret,
                    'secret_fingerprint' => $fingerprint,
                ]);

                return $locked->fresh();
            });
        } catch (UniqueConstraintViolationException) {
            throw new InvalidArgumentException('Duplicate digital unit fingerprint.');
        }
    }

    public function deleteUnit(ChatCatalogDigitalInventoryUnit $unit): void
    {
        $status = $unit->status instanceof ChatCatalogDigitalUnitStatus
            ? $unit->status
            : ChatCatalogDigitalUnitStatus::from((string) $unit->status);

        if (! in_array($status, [
            ChatCatalogDigitalUnitStatus::Available,
            ChatCatalogDigitalUnitStatus::Disabled,
            ChatCatalogDigitalUnitStatus::Expired,
            ChatCatalogDigitalUnitStatus::Revoked,
        ], true)) {
            throw new InvalidArgumentException('لا يمكن حذف هذه الوحدة في حالتها الحالية.');
        }

        $unit->delete();
    }

    public function deletePool(ChatCatalogDigitalInventoryPool $pool): void
    {
        $blocked = ChatCatalogDigitalInventoryUnit::withoutGlobalScopes()
            ->where('workspace_id', $pool->workspace_id)
            ->where('chat_catalog_digital_inventory_pool_id', $pool->id)
            ->whereIn('status', [
                ChatCatalogDigitalUnitStatus::Reserved->value,
                ChatCatalogDigitalUnitStatus::Delivered->value,
            ])
            ->exists();

        if ($blocked) {
            throw new InvalidArgumentException('لا يمكن حذف الحوض أثناء وجود وحدات محجوزة أو مسلّمة.');
        }

        $pool->delete();
    }

    public function transition(
        ChatCatalogDigitalInventoryUnit $unit,
        ChatCatalogDigitalUnitStatus $next,
    ): ChatCatalogDigitalInventoryUnit {
        return DB::transaction(function () use ($unit, $next): ChatCatalogDigitalInventoryUnit {
            $locked = ChatCatalogDigitalInventoryUnit::withoutGlobalScopes()
                ->where('workspace_id', $unit->workspace_id)
                ->whereKey($unit->id)
                ->lockForUpdate()
                ->firstOrFail();
            $this->transitionLocked($locked, $next);

            return $locked->fresh();
        });
    }

    public function expireDueUnits(ChatCatalogDigitalInventoryPool $pool): int
    {
        return ChatCatalogDigitalInventoryUnit::withoutGlobalScopes()
            ->where('workspace_id', $pool->workspace_id)
            ->where('chat_catalog_digital_inventory_pool_id', $pool->id)
            ->whereIn('status', [
                ChatCatalogDigitalUnitStatus::Available->value,
                ChatCatalogDigitalUnitStatus::Reserved->value,
            ])
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->update([
                'status' => ChatCatalogDigitalUnitStatus::Expired->value,
                'reservation_key' => null,
                'reserved_until' => null,
                'updated_at' => now(),
            ]);
    }

    public function releaseExpiredReservations(ChatCatalogDigitalInventoryPool $pool): int
    {
        return ChatCatalogDigitalInventoryUnit::withoutGlobalScopes()
            ->where('workspace_id', $pool->workspace_id)
            ->where('chat_catalog_digital_inventory_pool_id', $pool->id)
            ->where('status', ChatCatalogDigitalUnitStatus::Reserved->value)
            ->whereNotNull('reserved_until')
            ->where('reserved_until', '<=', now())
            ->update([
                'status' => ChatCatalogDigitalUnitStatus::Available->value,
                'reservation_key' => null,
                'reserved_until' => null,
                'updated_at' => now(),
            ]);
    }

    private function transitionLocked(
        ChatCatalogDigitalInventoryUnit $unit,
        ChatCatalogDigitalUnitStatus $next,
    ): void {
        $current = $unit->status instanceof ChatCatalogDigitalUnitStatus
            ? $unit->status
            : ChatCatalogDigitalUnitStatus::from((string) $unit->status);

        if (! $current->canTransitionTo($next)) {
            throw new InvalidArgumentException("Invalid digital unit transition {$current->value} -> {$next->value}.");
        }

        $unit->update(['status' => $next]);
    }

    public function ensureDigitalProfile(
        ChatCatalogItem $item,
        ?ChatCatalogItemVariant $variant,
    ): ChatCatalogItemDigitalProfile {
        $subtype = $item->digital_subtype ?? 'custom_digital';
        $unitType = match ($subtype) {
            'code' => ChatCatalogDigitalUnitType::Code,
            'license' => ChatCatalogDigitalUnitType::License,
            'credential', 'account' => ChatCatalogDigitalUnitType::Credential,
            'subscription' => ChatCatalogDigitalUnitType::Subscription,
            'file' => ChatCatalogDigitalUnitType::File,
            default => ChatCatalogDigitalUnitType::Custom,
        };

        return ChatCatalogItemDigitalProfile::withoutGlobalScopes()->firstOrCreate(
            [
                'workspace_id' => $item->workspace_id,
                'chat_catalog_item_id' => $item->id,
                'chat_catalog_item_variant_id' => $variant?->id,
            ],
            ['unit_type' => $unitType],
        );
    }

    private function assertSameWorkspace(
        Workspace $workspace,
        ChatCatalogItem $item,
        ?ChatCatalogItemVariant $variant,
    ): void {
        if ((int) $item->workspace_id !== (int) $workspace->id) {
            throw new InvalidArgumentException('Item workspace mismatch.');
        }
        if ($variant && (int) $variant->workspace_id !== (int) $workspace->id) {
            throw new InvalidArgumentException('Variant workspace mismatch.');
        }
    }
}
