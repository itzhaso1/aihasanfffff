<?php

namespace App\Observers;

use App\Models\ChatCatalogDigitalInventoryPool;
use App\Models\ChatCatalogDigitalInventoryUnit;
use App\Models\ChatCatalogItemDigitalProfile;
use App\Models\User;
use App\Services\Audit\AuditLogService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * Digital catalog audit trail that intentionally omits secrets, fingerprints,
 * reservation tokens, and private storage locations.
 */
final class ChatCatalogSensitiveAuditObserver
{
    public function __construct(private readonly AuditLogService $auditLogService) {}

    public function created(Model $model): void
    {
        $this->log('created', $model, null, $this->safeAttributes($model));
    }

    public function updated(Model $model): void
    {
        $changes = $model->getChanges();
        if ($changes === []) {
            return;
        }

        $oldValues = collect($changes)
            ->keys()
            ->mapWithKeys(fn (string $key): array => [$key => $model->getOriginal($key)])
            ->all();

        $this->log('updated', $model, $this->redact($oldValues), $this->redact($changes));
    }

    public function deleted(Model $model): void
    {
        $this->log('deleted', $model, $this->safeAttributes($model), null);
    }

    private function log(string $event, Model $model, ?array $oldValues, ?array $newValues): void
    {
        $entity = match (true) {
            $model instanceof ChatCatalogDigitalInventoryPool => 'chat_catalog_digital_pool',
            $model instanceof ChatCatalogDigitalInventoryUnit => 'chat_catalog_digital_unit',
            $model instanceof ChatCatalogItemDigitalProfile => 'chat_catalog_digital_profile',
            default => 'chat_catalog_sensitive_entity',
        };

        $this->auditLogService->log(
            action: $this->action($entity, $event, $model, $newValues ?? []),
            entityType: $model::class,
            entityId: $model->getKey(),
            oldValues: $oldValues,
            newValues: $newValues,
            actor: Auth::user() instanceof User ? Auth::user() : null,
            workspaceId: $model->workspace_id !== null ? (int) $model->workspace_id : null,
        );
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    private function action(string $entity, string $event, Model $model, array $changes): string
    {
        if ($model instanceof ChatCatalogDigitalInventoryUnit) {
            if ($event === 'created') {
                return $model->unit_type?->value === 'file'
                    ? 'chat_catalog_private_asset_changed'
                    : 'chat_catalog_digital_unit_imported';
            }
            if ($event === 'updated' && array_key_exists('status', $changes)) {
                return match ($model->status?->value) {
                    'reserved' => 'chat_catalog_digital_unit_reserved',
                    'consumed' => 'chat_catalog_digital_unit_consumed',
                    'delivered' => 'chat_catalog_digital_unit_delivered',
                    'revoked' => 'chat_catalog_digital_unit_revoked',
                    'expired' => 'chat_catalog_digital_unit_expired',
                    'disabled' => 'chat_catalog_digital_unit_disabled',
                    default => 'chat_catalog_digital_unit_updated',
                };
            }
            if ($event === 'updated' && array_key_exists('encrypted_secret', $changes)) {
                return 'chat_catalog_credentials_replaced';
            }
            if ($event === 'updated' && array_key_exists('metadata', $changes)) {
                return 'chat_catalog_private_asset_changed';
            }
        }

        return $entity.'_'.$event;
    }

    /**
     * @return array<string, mixed>
     */
    private function safeAttributes(Model $model): array
    {
        return $this->redact($model->attributesToArray());
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function redact(array $values): array
    {
        unset(
            $values['encrypted_secret'],
            $values['secret_fingerprint'],
            $values['reservation_key'],
            $values['reserved_until'],
        );

        if (array_key_exists('metadata', $values)) {
            $values['metadata'] = '[redacted private metadata]';
        }

        if (array_key_exists('settings', $values)) {
            $values['settings'] = '[redacted sensitive settings]';
        }

        return $values;
    }
}
