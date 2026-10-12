<?php

namespace App\Observers;

use App\Models\ChatCatalog;
use App\Models\ChatCatalogCategory;
use App\Models\ChatCatalogCommunicationProfile;
use App\Models\ChatCatalogItem;
use App\Models\ChatCatalogItemMedia;
use App\Models\User;
use App\Services\Audit\AuditLogService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

final class ChatCatalogAuditObserver
{
    public function __construct(private readonly AuditLogService $auditLogService) {}

    public function created(Model $model): void
    {
        $this->log($this->createdAction($model), $model, null, $this->safe($model->attributesToArray()));
    }

    public function updated(Model $model): void
    {
        $changes = collect($model->getChanges())->except(['updated_at'])->all();
        if ($changes === []) {
            return;
        }

        $old = collect($changes)
            ->keys()
            ->mapWithKeys(fn (string $key): array => [$key => $model->getOriginal($key)])
            ->all();

        $this->log($this->updatedAction($model, $changes), $model, $this->safe($old), $this->safe($changes));
    }

    public function deleted(Model $model): void
    {
        $action = $model instanceof ChatCatalogItem
            ? 'chat_catalog_item_archived'
            : $this->entityPrefix($model).'_deleted';
        $this->log($action, $model, $this->safe($model->attributesToArray()), null);
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    private function updatedAction(Model $model, array $changes): string
    {
        if ($model instanceof ChatCatalog) {
            if (array_key_exists('status', $changes)) {
                return match ((string) $model->status?->value) {
                    'published' => 'chat_catalog_published',
                    'paused' => 'chat_catalog_paused',
                    'archived' => 'chat_catalog_archived',
                    'draft' => 'chat_catalog_drafted',
                    default => 'chat_catalog_status_changed',
                };
            }

            return 'chat_catalog_settings_changed';
        }

        if ($model instanceof ChatCatalogItem) {
            if (array_key_exists('publication_status', $changes)) {
                return $model->publication_status === 'archived'
                    ? 'chat_catalog_item_archived'
                    : 'chat_catalog_item_published';
            }
            if (array_intersect(array_keys($changes), ['price', 'currency']) !== []) {
                return 'chat_catalog_item_price_changed';
            }
            if (array_intersect(array_keys($changes), ['stock', 'inventory_policy']) !== []) {
                return 'chat_catalog_item_stock_policy_changed';
            }

            return 'chat_catalog_item_updated';
        }

        if ($model instanceof ChatCatalogCommunicationProfile) {
            return 'chat_catalog_communication_changed';
        }

        return $this->entityPrefix($model).'_updated';
    }

    private function createdAction(Model $model): string
    {
        return match (true) {
            $model instanceof ChatCatalog => 'chat_catalog_created',
            $model instanceof ChatCatalogItem => 'chat_catalog_item_created',
            $model instanceof ChatCatalogCategory => 'chat_catalog_category_created',
            $model instanceof ChatCatalogCommunicationProfile => 'chat_catalog_communication_created',
            $model instanceof ChatCatalogItemMedia => 'chat_catalog_media_created',
            default => $this->entityPrefix($model).'_created',
        };
    }

    private function entityPrefix(Model $model): string
    {
        return match (true) {
            $model instanceof ChatCatalogCategory => 'chat_catalog_category',
            $model instanceof ChatCatalogItemMedia => 'chat_catalog_media',
            default => 'chat_catalog',
        };
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function safe(array $values): array
    {
        unset($values['deleted_at']);

        return $values;
    }

    private function log(string $action, Model $model, ?array $oldValues, ?array $newValues): void
    {
        $this->auditLogService->log(
            action: $action,
            entityType: $model::class,
            entityId: $model->getKey(),
            oldValues: $oldValues,
            newValues: $newValues,
            actor: Auth::user() instanceof User ? Auth::user() : null,
            workspaceId: $model->workspace_id !== null ? (int) $model->workspace_id : null,
        );
    }
}
