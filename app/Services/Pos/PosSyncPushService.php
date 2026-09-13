<?php

namespace App\Services\Pos;

use App\Exceptions\PosSyncOperationException;
use App\Models\Customer;
use App\Models\DiningTable;
use App\Models\InventoryMovement;
use App\Models\Order;
use App\Models\PosDevice;
use App\Models\PosItemCategory;
use App\Models\PosMenuItem;
use App\Models\PosSyncChange;
use App\Models\PosSyncOperation;
use App\Models\TableSession;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Feature\FeatureAccessService;
use App\Services\Inventory\InventoryService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

/**
 * Batch POS push — idempotent by (workspace_id, operation_uuid).
 *
 * Allowed cashier contract:
 *   kitchen orders (paid or unpaid) → POS then Laravel then kitchen pull
 *   kitchen order status (pos_status only) → POS then Laravel change log
 *   invoices / reporting snapshots → POS then Laravel
 *   menu (categories / products / prices) → Flutter then Laravel
 *   table master data (create / rename / delete) → Flutter then Laravel
 *
 * Table sessions and non-sale stock movements are accepted on this path.
 * Sale stock.movement is skipped so order.created is the only sale deduct.
 */
class PosSyncPushService
{
    public const MAX_OPERATIONS = 50;

    public function __construct(
        private readonly PosOrderService $orders,
        private readonly InventoryService $inventory,
        private readonly FeatureAccessService $features,
        private readonly PosDeviceRegistry $devices,
        private readonly PosSyncPullService $pull,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $operations
     * @return array{
     *     success: bool,
     *     accepted: list<array<string, mixed>>,
     *     failed: list<array<string, mixed>>,
     *     server_cursor: int,
     *     device_id: string
     * }
     */
    public function push(Workspace $workspace, User $user, string $deviceId, array $operations): array
    {
        if (! $this->features->workspaceHasFeature($workspace, 'pos')) {
            throw new HttpException(403, 'الكاشير غير متاح في باقتك الحالية');
        }

        $device = $this->devices->requireRegistered($workspace, $deviceId);
        $this->bindOriginDevice($deviceId);

        $accepted = [];
        $failed = [];

        foreach ($operations as $operation) {
            $uuid = trim((string) ($operation['id'] ?? ''));
            $type = trim((string) ($operation['type'] ?? ''));
            if ($uuid === '' || $type === '') {
                $failed[] = [
                    'id' => $uuid === '' ? null : $uuid,
                    'type' => $type === '' ? null : $type,
                    'error' => 'كل عملية تحتاج id و type.',
                    'retryable' => false,
                ];

                continue;
            }

            try {
                $accepted[] = $this->processOne($workspace, $user, $device, $operation);
            } catch (PosSyncOperationException $e) {
                $this->rememberFailure($workspace, $deviceId, $operation, $e->getMessage());
                $failed[] = [
                    'id' => $uuid,
                    'type' => $type,
                    'error' => $e->getMessage(),
                    'retryable' => $e->retryable,
                ];
            } catch (RuntimeException $e) {
                $this->rememberFailure($workspace, $deviceId, $operation, $e->getMessage());
                $failed[] = [
                    'id' => $uuid,
                    'type' => $type,
                    'error' => $e->getMessage(),
                    'retryable' => false,
                ];
            } catch (Throwable $e) {
                Log::warning('pos.sync.push_operation_failed', [
                    'workspace_id' => $workspace->id,
                    'device_id' => $deviceId,
                    'operation_uuid' => $uuid,
                    'error' => $e->getMessage(),
                ]);
                $this->rememberFailure($workspace, $deviceId, $operation, $e->getMessage());
                $failed[] = [
                    'id' => $uuid,
                    'type' => $type,
                    'error' => 'تعذّر معالجة العملية مؤقتاً.',
                    'retryable' => true,
                ];
            }
        }

        $cursor = $this->serverCursor($workspace);
        $error = $failed === [] ? null : (string) ($failed[0]['error'] ?? null);
        $this->devices->touch($device, $cursor, $error);

        return [
            'success' => $failed === [],
            'accepted' => $accepted,
            'failed' => $failed,
            'server_cursor' => $cursor,
            'device_id' => $device->device_id,
        ];
    }

    /**
     * @return array{cursor: int, server_cursor: int, has_more: bool, changes: list<array<string, mixed>>, device_id: string}
     */
    public function pull(Workspace $workspace, string $deviceId, int $cursor, int $limit = 200): array
    {
        if (! $this->features->workspaceHasFeature($workspace, 'pos')) {
            throw new HttpException(403, 'الكاشير غير متاح في باقتك الحالية');
        }

        $device = $this->devices->requireRegistered($workspace, $deviceId);
        $payload = $this->pull->changes($workspace, $cursor, $limit);
        $this->devices->touch($device, (int) $payload['server_cursor']);
        $payload['device_id'] = $device->device_id;

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $operation
     * @return array<string, mixed>
     */
    private function processOne(Workspace $workspace, User $user, PosDevice $device, array $operation): array
    {
        $uuid = trim((string) $operation['id']);
        $type = trim((string) $operation['type']);
        $data = is_array($operation['data'] ?? null) ? $operation['data'] : [];

        return DB::transaction(function () use ($workspace, $user, $device, $operation, $uuid, $type, $data): array {
            $row = PosSyncOperation::withoutGlobalScopes()
                ->where('workspace_id', $workspace->id)
                ->where('operation_uuid', $uuid)
                ->lockForUpdate()
                ->first();

            if (! $row) {
                $row = $this->claim($workspace, $device, $uuid, $type, $operation);
                $row = PosSyncOperation::withoutGlobalScopes()
                    ->whereKey($row->id)
                    ->lockForUpdate()
                    ->firstOrFail();
            }

            $this->assertCompatiblePayload($row, $operation);

            if ($row->status === PosSyncOperation::STATUS_ACCEPTED || $row->entity_id) {
                return $this->ack($row, 'duplicate');
            }

            $handled = $this->dispatch($workspace, $user, $device, $type, $data, $uuid);
            $row->fill([
                'status' => PosSyncOperation::STATUS_ACCEPTED,
                'entity_type' => $handled['entity_type'] ?? null,
                'entity_id' => $handled['entity_id'] ?? null,
                'result_payload' => $handled['result'] ?? [],
                'last_error' => null,
                'attempts' => (int) $row->attempts + 1,
                'processed_at' => now(),
            ]);
            $row->save();

            return $this->ack($row, 'applied');
        });
    }

    /**
     * @param  array<string, mixed>  $operation
     */
    private function assertCompatiblePayload(PosSyncOperation $row, array $operation): void
    {
        $stored = is_array($row->request_payload) ? $row->request_payload : [];
        if ($stored === []) {
            return;
        }

        if ($this->operationFingerprint($stored) === $this->operationFingerprint($operation)) {
            return;
        }

        throw PosSyncOperationException::permanent('عملية بنفس المعرّف موجودة ببيانات مختلفة.', 409);
    }

    /**
     * @param  array<string, mixed>  $operation
     */
    private function operationFingerprint(array $operation): string
    {
        $type = trim((string) ($operation['type'] ?? ''));
        $data = is_array($operation['data'] ?? null) ? $operation['data'] : [];
        $normalized = ['type' => $type, 'data' => $this->canonicalize($data)];

        return hash('sha256', json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '');
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
        }

        ksort($value);
        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize($item);
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $operation
     */
    private function claim(Workspace $workspace, PosDevice $device, string $uuid, string $type, array $operation): PosSyncOperation
    {
        try {
            return PosSyncOperation::withoutGlobalScopes()->create([
                'workspace_id' => $workspace->id,
                'device_id' => $device->device_id,
                'operation_uuid' => $uuid,
                'type' => $type,
                'status' => PosSyncOperation::STATUS_SYNCING,
                'request_payload' => $operation,
                'attempts' => 1,
            ]);
        } catch (QueryException $e) {
            $existing = PosSyncOperation::withoutGlobalScopes()
                ->where('workspace_id', $workspace->id)
                ->where('operation_uuid', $uuid)
                ->first();
            if ($existing) {
                return $existing;
            }
            throw $e;
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{entity_type: ?string, entity_id: ?int, result: array<string, mixed>}
     */
    private function dispatch(Workspace $workspace, User $user, PosDevice $device, string $type, array $data, string $uuid): array
    {
        return match ($type) {
            'order.created' => $this->orderCreated($workspace, $user, $data, $uuid),
            'order.updated' => $this->orderUpdated($workspace, $data),
            'order.deleted' => $this->orderDeleted($workspace, $user, $data),
            'customer.created' => $this->customerCreated($workspace, $data, $uuid),
            'table_session.open' => $this->sessionOpen($workspace, $device, $data),
            'table_session.close' => $this->sessionClose($workspace, $user, $data),
            'table_session.cancel' => $this->sessionCancel($workspace, $user, $data),
            'table_session.note' => $this->sessionNote($workspace, $data),
            'table_session.discount' => $this->sessionDiscount($workspace, $data),
            'table_session.transfer' => $this->sessionTransfer($workspace, $data),
            'table_session.merge' => $this->sessionMerge($workspace, $data),
            'table_session.split' => $this->sessionSplit($workspace, $user, $data),
            'invoice.created' => $this->invoiceCreated($workspace, $user, $data),
            'category.created' => $this->categoryCreated($workspace, $data),
            'category.updated' => $this->categoryUpdated($workspace, $data),
            'category.deleted' => $this->categoryDeleted($workspace, $data),
            'product.created' => $this->productCreated($workspace, $data),
            'product.updated' => $this->productUpdated($workspace, $data),
            'product.deleted' => $this->productDeleted($workspace, $data),
            'table.created' => $this->tableCreated($workspace, $data),
            'table.updated' => $this->tableUpdated($workspace, $data),
            'table.deleted' => $this->tableDeleted($workspace, $data),
            'stock.movement' => $this->stockMovement($workspace, $user, $data, $uuid),
            default => throw PosSyncOperationException::permanent('نوع عملية غير مدعوم: '.$type),
        };
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{entity_type: string, entity_id: int, result: array<string, mixed>}
     */
    private function orderCreated(Workspace $workspace, User $user, array $data, string $uuid): array
    {
        if (empty($data['client_reference'])) {
            $data['client_reference'] = $uuid;
        }
        if (empty($data['dining_table_id']) && ! empty($data['table_server_id'])) {
            $data['dining_table_id'] = $data['table_server_id'];
        }
        if (isset($data['items']) && is_array($data['items'])) {
            $data['items'] = array_values(array_map(
                fn ($item): array => $this->mapOrderCreatedItem($item),
                $data['items'],
            ));
        }

        $order = $this->orders->createPosOrder($workspace, $data, $user);
        $order->load(['items', 'table', 'customer']);

        return [
            'entity_type' => 'order',
            'entity_id' => (int) $order->id,
            'result' => $this->orderResult($order),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{entity_type: string, entity_id: int, result: array<string, mixed>}
     */
    private function orderUpdated(Workspace $workspace, array $data): array
    {
        $order = $this->resolveOrder($workspace, $data);
        $updated = $this->isKitchenStatusUpdate($data)
            ? $this->orders->applyKitchenPosStatus(
                $order,
                trim((string) ($data['pos_status'] ?? '')),
            )
            : $this->orders->updateOrderItems($order, $data);
        $updated->load(['items', 'table', 'customer']);

        return [
            'entity_type' => 'order',
            'entity_id' => (int) $updated->id,
            'result' => $this->orderResult($updated),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function isKitchenStatusUpdate(array $data): bool
    {
        $status = trim((string) ($data['pos_status'] ?? ''));
        if ($status === '') {
            return false;
        }
        if (filter_var($data['kitchen_status'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            return true;
        }
        $items = $data['items'] ?? null;

        return ! is_array($items) || $items === [];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{entity_type: string, entity_id: int, result: array<string, mixed>}
     */
    private function orderDeleted(Workspace $workspace, User $user, array $data): array
    {
        $order = $this->resolveOrder($workspace, $data);
        $updated = $this->orders->deletePosOrder($order, $user);

        return [
            'entity_type' => 'order',
            'entity_id' => (int) $updated->id,
            'result' => [
                'id' => $updated->id,
                'pos_status' => $updated->pos_status,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{entity_type: string, entity_id: int, result: array<string, mixed>}
     */
    private function customerCreated(Workspace $workspace, array $data, string $uuid): array
    {
        $name = trim((string) ($data['name'] ?? ''));
        $phone = trim((string) ($data['phone'] ?? ''));
        $clientRef = trim((string) ($data['client_reference'] ?? $uuid));
        if ($name === '' || $phone === '') {
            throw PosSyncOperationException::permanent('اسم ورقم هاتف العميل مطلوبان.');
        }

        if ($clientRef !== '') {
            $existing = Customer::withoutGlobalScopes()
                ->where('workspace_id', $workspace->id)
                ->where('client_reference', $clientRef)
                ->first();
            if ($existing) {
                return [
                    'entity_type' => 'customer',
                    'entity_id' => (int) $existing->id,
                    'result' => $this->customerResult($existing),
                ];
            }
        }

        $byPhone = Customer::withoutGlobalScopes()
            ->where('workspace_id', $workspace->id)
            ->where('phone', $phone)
            ->first();
        if ($byPhone) {
            if ($clientRef !== '' && empty($byPhone->client_reference)) {
                $byPhone->client_reference = $clientRef;
                $byPhone->save();
            }

            return [
                'entity_type' => 'customer',
                'entity_id' => (int) $byPhone->id,
                'result' => $this->customerResult($byPhone),
            ];
        }

        $customer = Customer::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => $name,
            'phone' => $phone,
            'client_reference' => $clientRef !== '' ? $clientRef : null,
            'orders_count' => 0,
            'total_purchases' => 0,
        ]);

        return [
            'entity_type' => 'customer',
            'entity_id' => (int) $customer->id,
            'result' => $this->customerResult($customer),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{entity_type: string, entity_id: int, result: array<string, mixed>}
     */
    /**
     * Open a table session from a Flutter device.
     *
     * QR Menu / guest bootstrap may already have an open session with no
     * opener device — those are joined (existing domain). Two Flutter
     * devices that each opened the same table offline are never merged.
     *
     * @param  array<string, mixed>  $data
     * @return array{entity_type: string, entity_id: int, result: array<string, mixed>}
     */
    private function sessionOpen(Workspace $workspace, PosDevice $device, array $data): array
    {
        $table = $this->resolveTable($workspace, $data);
        $localSessionId = trim((string) ($data['session_client_id'] ?? $data['client_reference'] ?? ''));
        $existing = TableSession::withoutGlobalScopes()
            ->where('workspace_id', $workspace->id)
            ->where('dining_table_id', $table->id)
            ->where('status', 'open')
            ->latest('id')
            ->first();

        if ($existing) {
            $opener = $this->sessionOpener($workspace, $existing);
            $ownerDevice = $opener?->device_id;
            $ownerLocal = $this->sessionClientIdFromOperation($opener);

            $sameClient = $localSessionId !== '' && $ownerLocal !== '' && hash_equals($ownerLocal, $localSessionId);
            $sameDevice = is_string($ownerDevice)
                && $ownerDevice !== ''
                && hash_equals((string) $ownerDevice, (string) $device->device_id);

            if ($sameClient || ($sameDevice && $ownerLocal === $localSessionId)) {
                return $this->sessionOpenResult($table, $existing, $localSessionId, $device->device_id);
            }

            if ($opener && $ownerDevice && ! $sameDevice) {
                $session = TableSession::query()->create([
                    'workspace_id' => $table->workspace_id,
                    'dining_table_id' => $table->id,
                    'status' => 'open',
                    'opened_at' => now(),
                ]);
                $table->update(['status' => 'occupied']);

                return $this->sessionOpenResult(
                    $table,
                    $session,
                    $localSessionId,
                    $device->device_id,
                    conflict: true,
                    conflictingSessionId: (int) $existing->id,
                    reason: 'table_session_open_conflict_other_device',
                );
            }
        }

        $session = $this->orders->openSession($table);

        return $this->sessionOpenResult($table, $session, $localSessionId, $device->device_id);
    }

    /**
     * @return array{entity_type: string, entity_id: int, result: array<string, mixed>}
     */
    private function sessionOpenResult(
        DiningTable $table,
        TableSession $session,
        string $localSessionId,
        string $deviceId,
        bool $conflict = false,
        ?int $conflictingSessionId = null,
        ?string $reason = null,
    ): array {
        $result = [
            'session_id' => $session->id,
            'table_id' => $table->id,
            'status' => $session->status,
            'opened_at' => optional($session->opened_at)?->toIso8601String(),
            'local_session_id' => $localSessionId !== '' ? $localSessionId : null,
            'server_session_id' => $conflict ? $conflictingSessionId : (int) $session->id,
            'device_id' => $deviceId,
            'conflict' => $conflict,
            'conflict_status' => $conflict ? 'conflict' : 'accepted',
        ];
        if ($conflict) {
            $result['accepted_session_id'] = (int) $session->id;
            $result['reason'] = $reason;
        }

        return [
            'entity_type' => 'table_session',
            'entity_id' => (int) $session->id,
            'result' => $result,
        ];
    }

    private function sessionOpener(Workspace $workspace, TableSession $session): ?PosSyncOperation
    {
        return PosSyncOperation::withoutGlobalScopes()
            ->where('workspace_id', $workspace->id)
            ->where('type', 'table_session.open')
            ->where('status', PosSyncOperation::STATUS_ACCEPTED)
            ->where('entity_type', 'table_session')
            ->where('entity_id', $session->id)
            ->latest('id')
            ->first();
    }

    private function sessionClientIdFromOperation(?PosSyncOperation $operation): string
    {
        if (! $operation) {
            return '';
        }

        $request = is_array($operation->request_payload) ? $operation->request_payload : [];
        $payload = is_array($request['data'] ?? null) ? $request['data'] : $request;

        return trim((string) ($payload['session_client_id'] ?? $payload['client_reference'] ?? ''));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{entity_type: string, entity_id: ?int, result: array<string, mixed>}
     */
    private function sessionClose(Workspace $workspace, User $user, array $data): array
    {
        $session = $this->resolveCloseableSession($workspace, $data);
        if (! $session) {
            return [
                'entity_type' => 'table_session',
                'entity_id' => null,
                'result' => ['invoice' => null, 'already_closed' => true],
            ];
        }

        $invoice = $this->orders->closeSession(
            $session,
            (int) $user->id,
            isset($data['payment_method']) ? (string) $data['payment_method'] : null,
        );

        return [
            'entity_type' => 'table_session',
            'entity_id' => (int) $session->id,
            'result' => [
                'session_id' => $session->id,
                'invoice' => $invoice ? [
                    'id' => $invoice->id,
                    'invoice_id' => $invoice->id,
                    'invoice_number' => $invoice->invoice_number,
                    'total_amount' => (float) $invoice->total_amount,
                    'currency' => $invoice->currency,
                ] : null,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{entity_type: string, entity_id: int, result: array<string, mixed>}
     */
    private function sessionCancel(Workspace $workspace, User $user, array $data): array
    {
        $session = $this->resolveSession($workspace, $data, required: true);
        $this->orders->cancelSession($session, $user);

        return [
            'entity_type' => 'table_session',
            'entity_id' => (int) $session->id,
            'result' => ['session_id' => $session->id, 'status' => 'cancelled'],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{entity_type: string, entity_id: int, result: array<string, mixed>}
     */
    private function sessionNote(Workspace $workspace, array $data): array
    {
        $session = $this->resolveSession($workspace, $data, required: true);
        $this->orders->applySessionNote($session, (string) ($data['notes'] ?? ''));

        return [
            'entity_type' => 'table_session',
            'entity_id' => (int) $session->id,
            'result' => ['session_id' => $session->id],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{entity_type: string, entity_id: int, result: array<string, mixed>}
     */
    private function sessionDiscount(Workspace $workspace, array $data): array
    {
        $session = $this->resolveSession($workspace, $data, required: true);
        $this->orders->applySessionDiscount($session, (float) ($data['discount_amount'] ?? 0));

        return [
            'entity_type' => 'table_session',
            'entity_id' => (int) $session->id,
            'result' => ['session_id' => $session->id],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{entity_type: string, entity_id: int, result: array<string, mixed>}
     */
    private function sessionTransfer(Workspace $workspace, array $data): array
    {
        $session = $this->resolveSession($workspace, $data, required: true);
        $targetId = (int) ($data['target_table_id'] ?? 0);
        $target = DiningTable::withoutGlobalScopes()
            ->where('workspace_id', $workspace->id)
            ->whereKey($targetId)
            ->first();
        if (! $target) {
            throw PosSyncOperationException::permanent('طاولة النقل غير موجودة.');
        }
        $this->orders->transferSession($session, $target);

        return [
            'entity_type' => 'table_session',
            'entity_id' => (int) $session->id,
            'result' => ['session_id' => $session->id, 'target_table_id' => $target->id],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{entity_type: string, entity_id: int, result: array<string, mixed>}
     */
    private function sessionMerge(Workspace $workspace, array $data): array
    {
        $session = $this->resolveSession($workspace, $data, required: true);
        $targetId = (int) ($data['target_table_id'] ?? 0);
        $target = DiningTable::withoutGlobalScopes()
            ->where('workspace_id', $workspace->id)
            ->whereKey($targetId)
            ->first();
        if (! $target) {
            throw PosSyncOperationException::permanent('طاولة الدمج غير موجودة.');
        }
        $this->orders->mergeSessions($session, $target);

        return [
            'entity_type' => 'table_session',
            'entity_id' => (int) $session->id,
            'result' => ['session_id' => $session->id, 'target_table_id' => $target->id],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{entity_type: string, entity_id: ?int, result: array<string, mixed>}
     */
    private function sessionSplit(Workspace $workspace, User $user, array $data): array
    {
        $session = $this->resolveSession($workspace, $data, required: false);
        $groups = $data['remote_groups']['groups'] ?? $data['groups'] ?? [];
        if (! $session || ! is_array($groups) || count($groups) < 2) {
            // Local split already created orders; remote split is best-effort.
            return [
                'entity_type' => 'table_session',
                'entity_id' => $session?->id,
                'result' => ['skipped' => true],
            ];
        }

        $orders = $this->orders->splitSessionByItems($session, $groups, $user);

        return [
            'entity_type' => 'table_session',
            'entity_id' => (int) $session->id,
            'result' => ['order_ids' => $orders->pluck('id')->all()],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{entity_type: string, entity_id: int, result: array<string, mixed>}
     */
    private function invoiceCreated(Workspace $workspace, User $user, array $data): array
    {
        $order = $this->resolveOrder($workspace, [
            'server_order_id' => $data['order_server_id'] ?? $data['server_order_id'] ?? null,
            'client_reference' => $data['order_local_id'] ?? $data['client_reference'] ?? null,
        ]);
        $invoice = $this->orders->createInvoiceFromOrder(
            $order,
            (int) $user->id,
            $this->orders->parseOfflineTimestamp($data['closed_at'] ?? null),
        );

        $metadata = is_array($invoice->metadata) ? $invoice->metadata : [];
        $localNumber = trim((string) ($data['local_invoice_number'] ?? ''));
        if ($localNumber !== '') {
            $metadata['local_invoice_number'] = $localNumber;
        }
        $paymentMethod = trim((string) ($data['payment_method'] ?? ''));
        if ($paymentMethod !== '') {
            $metadata['payment_method'] = $paymentMethod;
        }
        if ($metadata !== (array) ($invoice->metadata ?? [])) {
            $invoice->update(['metadata' => $metadata]);
        }

        $order->refresh();

        // Same cashier cash mechanism as closeSession: metadata.payment_method
        // + payment_status. No Payment row and no gateway link.
        if ($paymentMethod !== '') {
            $orderMetadata = is_array($order->metadata) ? $order->metadata : [];
            $orderMetadata['payment_method'] = $paymentMethod;
            $order->update([
                'metadata' => $orderMetadata,
                'payment_status' => in_array($paymentMethod, ['cash', 'card', 'cashier', 'pay_now', 'transfer'], true)
                    ? 'paid'
                    : $order->payment_status,
            ]);
            $order->refresh();
        }

        return [
            'entity_type' => 'invoice',
            'entity_id' => (int) $invoice->id,
            'result' => [
                'invoice_id' => $invoice->id,
                'id' => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
                'local_invoice_number' => $metadata['local_invoice_number'] ?? null,
                'total_amount' => (float) $invoice->total_amount,
                'subtotal' => (float) $invoice->subtotal,
                'discount_amount' => (float) $invoice->discount_amount,
                'tax_amount' => (float) $order->tax_amount,
                'currency' => $invoice->currency,
                'payment_method' => is_array($order->metadata) ? ($order->metadata['payment_method'] ?? null) : null,
                'payment_status' => $order->payment_status,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{entity_type: string, entity_id: int, result: array<string, mixed>}
     */
    private function categoryCreated(Workspace $workspace, array $data): array
    {
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            throw PosSyncOperationException::permanent('اسم التصنيف مطلوب.');
        }

        $existing = PosItemCategory::withoutGlobalScopes()
            ->withTrashed()
            ->where('workspace_id', $workspace->id)
            ->where('name', $name)
            ->first();
        if ($existing) {
            if ($existing->trashed()) {
                $existing->restore();
            }
            $existing->fill([
                'is_active' => array_key_exists('is_active', $data) ? (bool) $data['is_active'] : true,
                'sort_order' => (int) ($data['sort_order'] ?? $existing->sort_order ?? 0),
            ])->save();

            return $this->categoryResult($existing->fresh() ?? $existing);
        }

        $category = PosItemCategory::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => $name,
            'is_active' => array_key_exists('is_active', $data) ? (bool) $data['is_active'] : true,
            'sort_order' => (int) ($data['sort_order'] ?? 0),
        ]);

        return $this->categoryResult($category);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{entity_type: string, entity_id: int, result: array<string, mixed>}
     */
    private function categoryUpdated(Workspace $workspace, array $data): array
    {
        $category = $this->resolveCategory($workspace, $data);
        $name = trim((string) ($data['name'] ?? $category->name));
        if ($name === '') {
            throw PosSyncOperationException::permanent('اسم التصنيف مطلوب.');
        }
        $taken = PosItemCategory::withoutGlobalScopes()
            ->where('workspace_id', $workspace->id)
            ->where('name', $name)
            ->whereKeyNot($category->id)
            ->exists();
        if ($taken) {
            throw PosSyncOperationException::permanent('يوجد تصنيف بنفس الاسم في هذه المساحة.');
        }
        $category->update([
            'name' => $name,
            'is_active' => array_key_exists('is_active', $data)
                ? (bool) $data['is_active']
                : (bool) $category->is_active,
            'sort_order' => (int) ($data['sort_order'] ?? $category->sort_order ?? 0),
        ]);

        return $this->categoryResult($category->fresh() ?? $category);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{entity_type: string, entity_id: int, result: array<string, mixed>}
     */
    private function categoryDeleted(Workspace $workspace, array $data): array
    {
        $category = $this->resolveCategory($workspace, $data);
        if ($category->items()->exists()) {
            throw PosSyncOperationException::permanent('لا يمكن حذف تصنيف مرتبط بأصناف. انقل الأصناف أولاً.');
        }
        $category->delete();

        return $this->categoryResult($category);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{entity_type: string, entity_id: int, result: array<string, mixed>}
     */
    private function productCreated(Workspace $workspace, array $data): array
    {
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            throw PosSyncOperationException::permanent('اسم الصنف مطلوب.');
        }
        if (! array_key_exists('price', $data) || ! is_numeric($data['price']) || (float) $data['price'] < 0) {
            throw PosSyncOperationException::permanent('سعر الصنف مطلوب.');
        }
        $categoryId = $this->optionalCategoryId($workspace, $data);
        $currency = strtoupper(trim((string) ($data['currency'] ?? 'SAR')));
        if (! preg_match('/^[A-Z]{3}$/', $currency)) {
            $currency = 'SAR';
        }

        $item = PosMenuItem::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => $name,
            'sku' => $this->nullableString($data['sku'] ?? null),
            'barcode' => $this->nullableString($data['barcode'] ?? null),
            'pos_item_category_id' => $categoryId,
            'item_type' => $this->nullableString($data['item_type'] ?? null) ?? 'عام',
            'price' => (float) $data['price'],
            'currency' => $currency,
            'is_active' => array_key_exists('is_active', $data) ? (bool) $data['is_active'] : true,
            'sort_order' => (int) ($data['sort_order'] ?? 0),
        ]);

        return $this->productResult($item);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{entity_type: string, entity_id: int, result: array<string, mixed>}
     */
    private function productUpdated(Workspace $workspace, array $data): array
    {
        $item = $this->resolveProduct($workspace, $data);
        $name = trim((string) ($data['name'] ?? $item->name));
        if ($name === '') {
            throw PosSyncOperationException::permanent('اسم الصنف مطلوب.');
        }
        $price = array_key_exists('price', $data) ? (float) $data['price'] : (float) $item->price;
        if ($price < 0) {
            throw PosSyncOperationException::permanent('سعر الصنف غير صالح.');
        }
        $currency = strtoupper(trim((string) ($data['currency'] ?? $item->currency ?? 'SAR')));
        if (! preg_match('/^[A-Z]{3}$/', $currency)) {
            $currency = (string) ($item->currency ?: 'SAR');
        }
        $item->update([
            'name' => $name,
            'sku' => array_key_exists('sku', $data) ? $this->nullableString($data['sku']) : $item->sku,
            'barcode' => array_key_exists('barcode', $data) ? $this->nullableString($data['barcode']) : $item->barcode,
            'pos_item_category_id' => array_key_exists('pos_item_category_id', $data) || array_key_exists('category_local_id', $data)
                ? $this->optionalCategoryId($workspace, $data)
                : $item->pos_item_category_id,
            'price' => $price,
            'currency' => $currency,
            'is_active' => array_key_exists('is_active', $data) ? (bool) $data['is_active'] : (bool) $item->is_active,
            'sort_order' => (int) ($data['sort_order'] ?? $item->sort_order ?? 0),
        ]);

        return $this->productResult($item->fresh() ?? $item);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{entity_type: string, entity_id: int, result: array<string, mixed>}
     */
    private function productDeleted(Workspace $workspace, array $data): array
    {
        $item = $this->resolveProduct($workspace, $data);
        $item->delete();

        return $this->productResult($item);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{entity_type: string, entity_id: int, result: array<string, mixed>}
     */
    private function tableCreated(Workspace $workspace, array $data): array
    {
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            throw PosSyncOperationException::permanent('اسم الطاولة مطلوب.');
        }

        $existing = DiningTable::withoutGlobalScopes()
            ->withTrashed()
            ->where('workspace_id', $workspace->id)
            ->where('name', $name)
            ->first();
        if ($existing) {
            if ($existing->trashed()) {
                $existing->restore();
            }
            $existing->fill(['name' => $name])->save();

            return $this->tableMasterResult($existing->fresh() ?? $existing);
        }

        $table = DiningTable::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => $name,
            'status' => 'available',
            'qr_token' => Str::random(48),
        ]);

        return $this->tableMasterResult($table);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{entity_type: string, entity_id: int, result: array<string, mixed>}
     */
    private function tableUpdated(Workspace $workspace, array $data): array
    {
        $table = $this->resolveMasterTable($workspace, $data);
        $name = trim((string) ($data['name'] ?? $table->name));
        if ($name === '') {
            throw PosSyncOperationException::permanent('اسم الطاولة مطلوب.');
        }
        $taken = DiningTable::withoutGlobalScopes()
            ->where('workspace_id', $workspace->id)
            ->where('name', $name)
            ->whereKeyNot($table->id)
            ->exists();
        if ($taken) {
            throw PosSyncOperationException::permanent('يوجد طاولة بنفس الاسم في هذه المساحة.');
        }
        $table->update(['name' => $name]);

        return $this->tableMasterResult($table->fresh() ?? $table);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{entity_type: string, entity_id: int, result: array<string, mixed>}
     */
    private function tableDeleted(Workspace $workspace, array $data): array
    {
        $table = $this->resolveMasterTable($workspace, $data);
        $open = TableSession::withoutGlobalScopes()
            ->where('workspace_id', $workspace->id)
            ->where('dining_table_id', $table->id)
            ->where('status', 'open')
            ->exists();
        if ($open) {
            throw PosSyncOperationException::permanent('لا يمكن حذف طاولة عليها جلسة مفتوحة.');
        }
        $table->delete();

        return $this->tableMasterResult($table);
    }

    /**
     * Standalone stock ledger. Sale deductions from order.created are skipped
     * so inventory is not double-applied.
     *
     * @param  array<string, mixed>  $data
     * @return array{entity_type: string, entity_id: ?int, result: array<string, mixed>}
     */
    private function stockMovement(Workspace $workspace, User $user, array $data, string $uuid): array
    {
        $kind = strtolower(trim((string) ($data['kind'] ?? $data['type'] ?? '')));
        $productId = (int) ($data['product_id'] ?? 0);
        $quantity = (int) ($data['quantity'] ?? 0);
        if ($productId <= 0 || $quantity <= 0 || $kind === '') {
            throw PosSyncOperationException::permanent('حركة المخزون تحتاج product_id و kind و quantity.');
        }

        if (in_array($kind, ['sale', 'remove'], true)) {
            return [
                'entity_type' => 'stock',
                'entity_id' => null,
                'result' => [
                    'skipped' => true,
                    'reason' => 'sale_applied_by_order',
                ],
            ];
        }

        $inventoryType = match ($kind) {
            'purchase', 'add' => 'add',
            'return' => 'return',
            'adjustment' => 'adjustment',
            'transfer', 'release' => 'release',
            default => throw PosSyncOperationException::permanent('نوع حركة مخزون غير مدعوم: '.$kind),
        };

        $movement = $this->inventory->adjustStock(
            productId: $productId,
            variantId: isset($data['variant_id']) ? (int) $data['variant_id'] : null,
            type: $inventoryType,
            quantity: $quantity,
            actor: $user,
            referenceType: isset($data['reference_type']) ? (string) $data['reference_type'] : InventoryMovement::class,
            referenceId: isset($data['reference_id']) ? (int) $data['reference_id'] : null,
            notes: (string) ($data['notes'] ?? 'POS sync '.$uuid),
        );

        return [
            'entity_type' => 'stock',
            'entity_id' => (int) $movement->id,
            'result' => [
                'id' => $movement->id,
                'product_id' => $movement->product_id,
                'type' => $movement->type,
                'quantity' => $movement->quantity,
                'before_quantity' => $movement->before_quantity,
                'after_quantity' => $movement->after_quantity,
            ],
        ];
    }

    /**
     * Pass through optional offline sale snapshot fields. Unknown item keys stay dropped.
     *
     * @return array<string, mixed>
     */
    private function mapOrderCreatedItem(mixed $item): array
    {
        $row = is_array($item) ? $item : [];
        $mapped = [
            'pos_menu_item_id' => $row['pos_menu_item_id'] ?? null,
        ];

        if (array_key_exists('quantity', $row)) {
            $mapped['quantity'] = $row['quantity'];
        } else {
            $mapped['quantity'] = 1;
        }

        foreach (['unit_price', 'discount_amount', 'tax_amount', 'name', 'product_name', 'notes'] as $key) {
            if (array_key_exists($key, $row)) {
                $mapped[$key] = $row[$key];
            }
        }

        return $mapped;
    }

    /**
     * @return array{entity_type: string, entity_id: int, result: array<string, mixed>}
     */
    private function categoryResult(PosItemCategory $category): array
    {
        return [
            'entity_type' => 'category',
            'entity_id' => (int) $category->id,
            'result' => [
                'id' => $category->id,
                'name' => $category->name,
                'is_active' => (bool) $category->is_active,
                'sort_order' => (int) $category->sort_order,
            ],
        ];
    }

    /**
     * @return array{entity_type: string, entity_id: int, result: array<string, mixed>}
     */
    private function productResult(PosMenuItem $item): array
    {
        return [
            'entity_type' => 'product',
            'entity_id' => (int) $item->id,
            'result' => [
                'id' => $item->id,
                'name' => $item->name,
                'price' => (float) $item->price,
                'currency' => $item->currency,
                'pos_item_category_id' => $item->pos_item_category_id,
                'is_active' => (bool) $item->is_active,
            ],
        ];
    }

    /**
     * @return array{entity_type: string, entity_id: int, result: array<string, mixed>}
     */
    private function tableMasterResult(DiningTable $table): array
    {
        return [
            'entity_type' => 'table',
            'entity_id' => (int) $table->id,
            'result' => [
                'id' => $table->id,
                'name' => $table->name,
                'status' => $table->status,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function resolveCategory(Workspace $workspace, array $data): PosItemCategory
    {
        $id = (int) ($data['server_id'] ?? $data['id'] ?? $data['pos_item_category_id'] ?? 0);
        if ($id > 0) {
            $category = PosItemCategory::withoutGlobalScopes()
                ->where('workspace_id', $workspace->id)
                ->whereKey($id)
                ->first();
            if ($category) {
                return $category;
            }
        }

        throw PosSyncOperationException::permanent('التصنيف غير موجود.');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function resolveProduct(Workspace $workspace, array $data): PosMenuItem
    {
        $id = (int) ($data['server_id'] ?? $data['id'] ?? $data['pos_menu_item_id'] ?? 0);
        if ($id > 0) {
            $item = PosMenuItem::withoutGlobalScopes()
                ->where('workspace_id', $workspace->id)
                ->whereKey($id)
                ->first();
            if ($item) {
                return $item;
            }
        }

        throw PosSyncOperationException::permanent('الصنف غير موجود.');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function resolveMasterTable(Workspace $workspace, array $data): DiningTable
    {
        $id = (int) ($data['server_id'] ?? $data['table_server_id'] ?? $data['dining_table_id'] ?? $data['id'] ?? 0);
        if ($id > 0) {
            $table = DiningTable::withoutGlobalScopes()
                ->where('workspace_id', $workspace->id)
                ->whereKey($id)
                ->first();
            if ($table) {
                return $table;
            }
        }

        throw PosSyncOperationException::permanent('الطاولة غير موجودة.');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function optionalCategoryId(Workspace $workspace, array $data): ?int
    {
        $id = (int) ($data['pos_item_category_id'] ?? $data['category_server_id'] ?? 0);
        if ($id <= 0) {
            return null;
        }
        $exists = PosItemCategory::withoutGlobalScopes()
            ->where('workspace_id', $workspace->id)
            ->whereKey($id)
            ->exists();
        if (! $exists) {
            throw PosSyncOperationException::permanent('تصنيف الصنف غير موجود.');
        }

        return $id;
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function resolveOrder(Workspace $workspace, array $data): Order
    {
        $id = (int) ($data['server_order_id'] ?? $data['order_server_id'] ?? $data['id'] ?? 0);
        if ($id > 0) {
            $order = Order::withoutGlobalScopes()
                ->where('workspace_id', $workspace->id)
                ->whereKey($id)
                ->first();
            if ($order) {
                return $order;
            }
        }

        $ref = trim((string) ($data['client_reference'] ?? $data['order_local_id'] ?? ''));
        if ($ref !== '') {
            $order = Order::withoutGlobalScopes()
                ->where('workspace_id', $workspace->id)
                ->where('client_reference', $ref)
                ->first();
            if ($order) {
                return $order;
            }
        }

        throw PosSyncOperationException::permanent('الطلب غير موجود.');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function resolveTable(Workspace $workspace, array $data): DiningTable
    {
        $id = (int) ($data['table_server_id'] ?? $data['dining_table_id'] ?? $data['table_id'] ?? 0);
        $table = DiningTable::withoutGlobalScopes()
            ->where('workspace_id', $workspace->id)
            ->whereKey($id)
            ->first();
        if (! $table) {
            throw PosSyncOperationException::permanent('الطاولة غير موجودة.');
        }

        return $table;
    }

    /**
     * Close the sitting the cashier asked for. If the payload session is
     * already gone, still close any leftover open session on that table so
     * Laravel occupancy/opened_at cannot outlive the cashier close.
     *
     * @param  array<string, mixed>  $data
     */
    private function resolveCloseableSession(Workspace $workspace, array $data): ?TableSession
    {
        $session = $this->resolveSession($workspace, $data, required: false);
        if ($session && ! in_array($session->status, ['closed', 'cancelled'], true)) {
            return $session;
        }

        try {
            $table = $this->resolveTable($workspace, $data);
        } catch (PosSyncOperationException) {
            return null;
        }

        return TableSession::withoutGlobalScopes()
            ->where('workspace_id', $workspace->id)
            ->where('dining_table_id', $table->id)
            ->where('status', 'open')
            ->latest('id')
            ->first();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function resolveSession(Workspace $workspace, array $data, bool $required): ?TableSession
    {
        $sessionId = (int) ($data['session_server_id'] ?? $data['session_id'] ?? 0);
        if ($sessionId > 0) {
            $session = TableSession::withoutGlobalScopes()
                ->where('workspace_id', $workspace->id)
                ->whereKey($sessionId)
                ->first();
            if ($session) {
                return $session;
            }
        }

        $localSessionId = trim((string) ($data['session_client_id'] ?? $data['client_reference'] ?? ''));
        if ($localSessionId !== '') {
            $ops = PosSyncOperation::withoutGlobalScopes()
                ->where('workspace_id', $workspace->id)
                ->where('type', 'table_session.open')
                ->where('status', PosSyncOperation::STATUS_ACCEPTED)
                ->where('entity_type', 'table_session')
                ->get();
            foreach ($ops as $op) {
                if ($this->sessionClientIdFromOperation($op) !== $localSessionId) {
                    continue;
                }
                if ($op->entity_id) {
                    $byClient = TableSession::withoutGlobalScopes()
                        ->where('workspace_id', $workspace->id)
                        ->whereKey((int) $op->entity_id)
                        ->first();
                    if ($byClient) {
                        return $byClient;
                    }
                }
            }
        }

        try {
            $table = $this->resolveTable($workspace, $data);
        } catch (PosSyncOperationException $e) {
            if ($required) {
                throw $e;
            }

            return null;
        }

        $open = TableSession::withoutGlobalScopes()
            ->where('workspace_id', $workspace->id)
            ->where('dining_table_id', $table->id)
            ->where('status', 'open')
            ->latest('id')
            ->first();

        if ($open) {
            return $open;
        }

        if ($required) {
            throw PosSyncOperationException::permanent('لا توجد جلسة مفتوحة لهذه الطاولة.');
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function orderResult(Order $order): array
    {
        return [
            'id' => $order->id,
            'order_number' => $order->order_number,
            'client_reference' => $order->client_reference,
            'pos_status' => $order->pos_status,
            'subtotal' => (float) $order->subtotal,
            'discount_amount' => (float) $order->discount_amount,
            'tax_amount' => (float) $order->tax_amount,
            'total_amount' => (float) $order->total_amount,
            'currency' => $order->currency,
            'dining_table_id' => $order->dining_table_id,
            'table_session_id' => $order->table_session_id,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function customerResult(Customer $customer): array
    {
        return [
            'id' => $customer->id,
            'name' => $customer->name,
            'phone' => $customer->phone,
            'client_reference' => $customer->client_reference,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function ack(PosSyncOperation $row, string $status): array
    {
        $result = is_array($row->result_payload) ? $row->result_payload : [];

        return [
            'id' => $row->operation_uuid,
            'status' => $status,
            'type' => $row->type,
            'entity_id' => $row->entity_id,
            'result' => $result,
        ];
    }

    /**
     * @param  array<string, mixed>  $operation
     */
    private function rememberFailure(Workspace $workspace, string $deviceId, array $operation, string $error): void
    {
        $uuid = trim((string) ($operation['id'] ?? ''));
        if ($uuid === '') {
            return;
        }

        $row = PosSyncOperation::withoutGlobalScopes()
            ->where('workspace_id', $workspace->id)
            ->where('operation_uuid', $uuid)
            ->first();
        if (! $row) {
            return;
        }
        if ($row->status === PosSyncOperation::STATUS_ACCEPTED) {
            return;
        }

        $row->fill([
            'status' => PosSyncOperation::STATUS_FAILED,
            'last_error' => $error,
            'device_id' => $deviceId,
        ]);
        $row->save();
    }

    private function serverCursor(Workspace $workspace): int
    {
        return (int) (PosSyncChange::withoutGlobalScopes()
            ->where('workspace_id', $workspace->id)
            ->max('id') ?? 0);
    }

    private function bindOriginDevice(string $deviceId): void
    {
        try {
            request()?->headers->set('X-Device-Id', $deviceId);
        } catch (Throwable) {
            // Observer origin is best-effort.
        }
    }
}
