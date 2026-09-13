<?php

namespace App\Observers;

use App\Models\Customer;
use App\Models\DiningTable;
use App\Models\InventoryMovement;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PosItemCategory;
use App\Models\PosMenuItem;
use App\Models\TableSession;
use App\Services\Pos\PosSyncChangeRecorder;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Database\Eloquent\Model;

/**
 * Records catalog / table / order / customer mutations into pos_sync_changes.
 * Soft deletes emit operation=delete so offline devices learn about removals.
 * Payments / invoices are intentionally NOT mirrored as offline financial workflows.
 */
class PosSyncChangeObserver
{
    public function __construct(
        private readonly PosSyncChangeRecorder $recorder,
    ) {}

    public function created(Model $model): void
    {
        $this->write($model, 'create');
    }

    public function updated(Model $model): void
    {
        $this->write($model, 'update');
    }

    public function deleted(Model $model): void
    {
        $this->write($model, 'delete');
    }

    public function forceDeleted(Model $model): void
    {
        $this->write($model, 'delete');
    }

    public function restored(Model $model): void
    {
        $this->write($model, 'update');
    }

    private function write(Model $model, string $operation): void
    {
        $workspaceId = (int) $model->getAttribute('workspace_id');
        if ($workspaceId > 0 && ! app(WorkspaceContext::class)->workspaceId() && ! app(WorkspaceContext::class)->isBypassingTenantScope()) {
            app(WorkspaceContext::class)->runForId($workspaceId, function () use ($model, $operation): void {
                $this->writeWithinContext($model, $operation);
            });

            return;
        }

        $this->writeWithinContext($model, $operation);
    }

    private function writeWithinContext(Model $model, string $operation): void
    {
        if ($model instanceof OrderItem) {
            $this->writeOrderItem($model, $operation);

            return;
        }

        if ($model instanceof TableSession) {
            $this->writeTableSession($model);

            return;
        }

        $entity = $this->entityType($model);
        if ($entity === null) {
            return;
        }

        if (! $model->getAttribute('workspace_id')) {
            return;
        }

        // Order::create runs before line items exist. An empty create snapshot
        // is useless to kitchen pull; OrderItem observers write the full order.
        if ($model instanceof Order && $operation === 'create') {
            if (! $model->relationLoaded('items')) {
                $model->load('items');
            }
            if ($model->items->isEmpty()) {
                return;
            }
        }

        $this->recorder->record($entity, $operation, $model, $this->originDeviceId());
    }

    private function writeOrderItem(OrderItem $item, string $operation): void
    {
        $order = $item->relationLoaded('order')
            ? $item->order
            : Order::withoutGlobalScopes()->find($item->order_id);

        if (! $order || ! $order->getAttribute('workspace_id')) {
            return;
        }

        // Item mutations surface as order updates with a full snapshot.
        $op = $operation === 'create' || $operation === 'update' || $operation === 'delete'
            ? 'update'
            : 'update';
        $this->recorder->recordOrderSnapshot($order, $op, $this->originDeviceId());
    }

    /**
     * A sitting opening or closing changes what the cashier must show for the
     * table even when the dining_tables row itself did not change (empty QR
     * visit, close of a sitting with no orders, reopen while already
     * occupied). Surface it as a table snapshot so pull carries session_id /
     * opened_at.
     */
    private function writeTableSession(TableSession $session): void
    {
        $table = $session->relationLoaded('table')
            ? $session->table
            : DiningTable::withoutGlobalScopes()->find($session->dining_table_id);

        if (! $table || ! $table->getAttribute('workspace_id')) {
            return;
        }

        $this->recorder->record(
            PosSyncChangeRecorder::ENTITY_TABLE,
            'update',
            $table,
            $this->originDeviceId(),
        );
    }

    private function originDeviceId(): ?string
    {
        try {
            $origin = request()?->header('X-Device-Id');
            if (is_string($origin)) {
                $origin = trim($origin);

                return $origin === '' ? null : $origin;
            }
        } catch (\Throwable) {
            return null;
        }

        return null;
    }

    private function entityType(Model $model): ?string
    {
        return match (true) {
            $model instanceof PosMenuItem => PosSyncChangeRecorder::ENTITY_PRODUCT,
            $model instanceof PosItemCategory => PosSyncChangeRecorder::ENTITY_CATEGORY,
            $model instanceof DiningTable => PosSyncChangeRecorder::ENTITY_TABLE,
            $model instanceof Order => PosSyncChangeRecorder::ENTITY_ORDER,
            $model instanceof Customer => PosSyncChangeRecorder::ENTITY_CUSTOMER,
            $model instanceof InventoryMovement => PosSyncChangeRecorder::ENTITY_STOCK,
            default => null,
        };
    }
}
