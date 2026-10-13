<?php

namespace App\Http\Controllers\Api\Mobile\V1;

use App\Enums\ChatCatalog\ChatCatalogDigitalUnitStatus;
use App\Enums\ChatCatalog\ChatCatalogDigitalUnitType;
use App\Http\Controllers\Api\Mobile\Concerns\AuthorizesChatCatalog;
use App\Http\Controllers\Api\Mobile\Concerns\ResolvesMobileWorkspace;
use App\Http\Controllers\Api\Mobile\MobileController;
use App\Http\Requests\Chat\StoreChatCatalogDigitalPoolRequest;
use App\Http\Requests\Chat\StoreChatCatalogDigitalUnitImportRequest;
use App\Http\Resources\Mobile\ChatCatalogDigitalPoolResource;
use App\Http\Resources\Mobile\ChatCatalogDigitalUnitResource;
use App\Models\ChatCatalogDigitalInventoryPool;
use App\Models\ChatCatalogDigitalInventoryUnit;
use App\Models\ChatCatalogItem;
use App\Models\ChatCatalogItemVariant;
use App\Services\Chat\ChatCatalogDigitalInventoryService;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use RuntimeException;

class ChatCatalogDigitalInventoryController extends MobileController
{
    use AuthorizesChatCatalog;
    use ResolvesMobileWorkspace;

    public function __construct(
        private readonly ChatCatalogDigitalInventoryService $inventory,
        private readonly WorkspaceContext $workspaceContext,
    ) {}

    public function pools(Request $request): JsonResponse
    {
        $workspace = $this->requireWorkspace($this->workspaceContext);
        $this->authorizeChatCatalogDigitalInventoryView($request, $workspace);
        $perPage = max(1, min(100, (int) $request->input('per_page', 30)));

        $paginator = ChatCatalogDigitalInventoryPool::query()
            ->withCount('availableUnits')
            ->orderBy('id')
            ->cursorPaginate($perPage);

        return $this->ok(
            ChatCatalogDigitalPoolResource::collection($paginator->items()),
            $this->cursorMeta($paginator->nextCursor()?->encode(), $paginator->previousCursor()?->encode(), $perPage),
        );
    }

    public function storePool(StoreChatCatalogDigitalPoolRequest $request): JsonResponse
    {
        $workspace = $this->requireWorkspace($this->workspaceContext);
        $this->authorizeChatCatalogDigitalInventoryManage($request, $workspace);
        $data = $request->validated();
        $item = ChatCatalogItem::query()->findOrFail((int) $data['chat_catalog_item_id']);
        $variant = isset($data['chat_catalog_item_variant_id'])
            ? ChatCatalogItemVariant::query()->findOrFail((int) $data['chat_catalog_item_variant_id'])
            : null;
        if ($variant && (int) $variant->chat_catalog_item_id !== (int) $item->id) {
            return $this->fail('النسخة لا تنتمي إلى المنتج.', 422);
        }

        try {
            $pool = $this->inventory->createPool(
                $workspace,
                $item,
                $data['name'],
                $variant,
                (int) ($data['low_stock_threshold'] ?? 5),
                (int) ($data['reservation_timeout_seconds'] ?? 900),
            );
        } catch (InvalidArgumentException $exception) {
            return $this->fail($exception->getMessage(), 422);
        }

        return $this->ok(new ChatCatalogDigitalPoolResource($pool->loadCount('availableUnits')), message: 'تم إنشاء مخزون رقمي.', status: 201);
    }

    public function units(Request $request, ChatCatalogDigitalInventoryPool $pool): JsonResponse
    {
        $workspace = $this->requireWorkspace($this->workspaceContext);
        $this->authorizeChatCatalogDigitalInventoryView($request, $workspace);
        $this->assertPoolWorkspace($pool, $workspace->id);
        $this->authorize('view', $pool);
        $perPage = max(1, min(100, (int) $request->input('per_page', 50)));

        $paginator = $pool->units()
            ->orderBy('id')
            ->cursorPaginate($perPage);

        return $this->ok(
            ChatCatalogDigitalUnitResource::collection($paginator->items()),
            $this->cursorMeta($paginator->nextCursor()?->encode(), $paginator->previousCursor()?->encode(), $perPage),
        );
    }

    public function importUnits(
        StoreChatCatalogDigitalUnitImportRequest $request,
        ChatCatalogDigitalInventoryPool $pool,
    ): JsonResponse {
        $workspace = $this->requireWorkspace($this->workspaceContext);
        $this->authorizeChatCatalogDigitalInventoryManage($request, $workspace);
        $this->assertPoolWorkspace($pool, $workspace->id);
        $this->authorize('update', $pool);
        $data = $request->validated();
        $type = ChatCatalogDigitalUnitType::from($data['unit_type']);

        try {
            if ($type === ChatCatalogDigitalUnitType::File) {
                $this->inventory->importPrivateFileUnit(
                    $pool,
                    $data['file_asset'] ?? [],
                    isset($data['expires_at']) ? new \DateTimeImmutable($data['expires_at']) : null,
                );

                return $this->ok([
                    'imported' => 1,
                    'skipped_duplicates' => 0,
                ], message: 'تم تسجيل الأصل الرقمي الخاص.');
            }

            $summary = $this->inventory->bulkImportSecrets(
                $pool,
                $type,
                $data['secrets'] ?? [],
                isset($data['expires_at']) ? new \DateTimeImmutable($data['expires_at']) : null,
            );
        } catch (InvalidArgumentException $exception) {
            return $this->fail($exception->getMessage(), 422);
        }

        return $this->ok($summary, message: 'تم استيراد الوحدات الرقمية.');
    }

    public function reserve(Request $request, ChatCatalogDigitalInventoryPool $pool): JsonResponse
    {
        $workspace = $this->requireWorkspace($this->workspaceContext);
        $this->authorizeChatCatalogDigitalInventoryManage($request, $workspace);
        $this->assertPoolWorkspace($pool, $workspace->id);
        $this->authorize('update', $pool);
        $data = $request->validate([
            'reservation_key' => ['required', 'string', 'max:128'],
            'ttl_seconds' => ['nullable', 'integer', 'between:60,86400'],
        ]);
        try {
            $unit = $this->inventory->reserveUnit($pool, $data['reservation_key'], (int) ($data['ttl_seconds'] ?? 900));
        } catch (RuntimeException|InvalidArgumentException $exception) {
            return $this->fail($exception->getMessage(), 422);
        }

        return $this->ok(new ChatCatalogDigitalUnitResource($unit), message: 'تم حجز الوحدة الرقمية.');
    }

    public function release(Request $request, ChatCatalogDigitalInventoryUnit $unit): JsonResponse
    {
        $workspace = $this->requireWorkspace($this->workspaceContext);
        $this->authorizeChatCatalogDigitalInventoryManage($request, $workspace);
        $this->assertUnitWorkspace($unit, $workspace->id);
        $this->authorize('update', $unit);
        $data = $request->validate(['reservation_key' => ['required', 'string', 'max:128']]);

        try {
            $released = $this->inventory->releaseReservation($unit, $data['reservation_key']);
        } catch (InvalidArgumentException $exception) {
            return $this->fail($exception->getMessage(), 422);
        }

        return $this->ok(new ChatCatalogDigitalUnitResource($released), message: 'تم تحرير الحجز.');
    }

    public function consume(Request $request, ChatCatalogDigitalInventoryUnit $unit): JsonResponse
    {
        $workspace = $this->requireWorkspace($this->workspaceContext);
        $this->authorizeChatCatalogDigitalInventoryManage($request, $workspace);
        $this->assertUnitWorkspace($unit, $workspace->id);
        $this->authorize('update', $unit);
        $data = $request->validate(['reservation_key' => ['required', 'string', 'max:128']]);

        try {
            $consumed = $this->inventory->consumeReserved($unit, $data['reservation_key']);
        } catch (InvalidArgumentException $exception) {
            return $this->fail($exception->getMessage(), 422);
        }

        return $this->ok(new ChatCatalogDigitalUnitResource($consumed), message: 'تم استهلاك الوحدة.');
    }

    public function transition(Request $request, ChatCatalogDigitalInventoryUnit $unit): JsonResponse
    {
        $workspace = $this->requireWorkspace($this->workspaceContext);
        $this->authorizeChatCatalogDigitalInventoryManage($request, $workspace);
        $this->assertUnitWorkspace($unit, $workspace->id);
        $this->authorize('update', $unit);
        $data = $request->validate([
            'status' => ['required', Rule::in([
                ChatCatalogDigitalUnitStatus::Revoked->value,
                ChatCatalogDigitalUnitStatus::Disabled->value,
                ChatCatalogDigitalUnitStatus::Expired->value,
            ])],
        ]);
        try {
            $updated = $this->inventory->transition($unit, ChatCatalogDigitalUnitStatus::from($data['status']));
        } catch (InvalidArgumentException $exception) {
            return $this->fail($exception->getMessage(), 422);
        }

        return $this->ok(new ChatCatalogDigitalUnitResource($updated), message: 'تم تحديث حالة الوحدة.');
    }

    public function replaceSecret(Request $request, ChatCatalogDigitalInventoryUnit $unit): JsonResponse
    {
        $workspace = $this->requireWorkspace($this->workspaceContext);
        $this->authorizeChatCatalogDigitalInventoryManage($request, $workspace);
        $this->assertUnitWorkspace($unit, $workspace->id);
        $this->authorize('update', $unit);
        $data = $request->validate(['secret' => ['required', 'string', 'min:1', 'max:10000']]);

        try {
            $updated = $this->inventory->replaceSecret($unit, $data['secret']);
        } catch (InvalidArgumentException $exception) {
            return $this->fail($exception->getMessage(), 422);
        }

        return $this->ok(
            new ChatCatalogDigitalUnitResource($updated),
            message: 'تم استبدال السر الرقمي دون إعادته في الاستجابة.',
        );
    }

    public function destroyPool(Request $request, ChatCatalogDigitalInventoryPool $pool): JsonResponse
    {
        $workspace = $this->requireWorkspace($this->workspaceContext);
        $this->authorizeChatCatalogDigitalInventoryManage($request, $workspace);
        $this->assertPoolWorkspace($pool, $workspace->id);
        $this->authorize('delete', $pool);

        try {
            $this->inventory->deletePool($pool);
        } catch (InvalidArgumentException $exception) {
            return $this->fail($exception->getMessage(), 422);
        }

        return $this->ok(message: 'تم حذف حوض المخزون الرقمي.');
    }

    public function destroyUnit(Request $request, ChatCatalogDigitalInventoryUnit $unit): JsonResponse
    {
        $workspace = $this->requireWorkspace($this->workspaceContext);
        $this->authorizeChatCatalogDigitalInventoryManage($request, $workspace);
        $this->assertUnitWorkspace($unit, $workspace->id);
        $this->authorize('delete', $unit);

        try {
            $this->inventory->deleteUnit($unit);
        } catch (InvalidArgumentException $exception) {
            return $this->fail($exception->getMessage(), 422);
        }

        return $this->ok(message: 'تم حذف الوحدة الرقمية.');
    }

    private function assertPoolWorkspace(ChatCatalogDigitalInventoryPool $pool, int $workspaceId): void
    {
        if ((int) $pool->workspace_id !== $workspaceId) {
            abort(404);
        }
    }

    private function assertUnitWorkspace(ChatCatalogDigitalInventoryUnit $unit, int $workspaceId): void
    {
        if ((int) $unit->workspace_id !== $workspaceId) {
            abort(404);
        }
    }
}
