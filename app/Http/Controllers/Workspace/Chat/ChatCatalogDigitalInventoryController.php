<?php

namespace App\Http\Controllers\Workspace\Chat;

use App\Enums\ChatCatalog\ChatCatalogDigitalUnitType;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Workspace\Concerns\InteractsWithWorkspace;
use App\Http\Requests\Chat\StoreChatCatalogDigitalPoolRequest;
use App\Http\Requests\Chat\StoreChatCatalogDigitalUnitImportRequest;
use App\Models\ChatCatalogDigitalInventoryPool;
use App\Models\ChatCatalogItem;
use App\Models\ChatCatalogItemVariant;
use App\Services\Chat\ChatCatalogDigitalInventoryService;
use App\Support\Chat\ChatCatalogAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;
use InvalidArgumentException;

class ChatCatalogDigitalInventoryController extends Controller
{
    use InteractsWithWorkspace;

    public function __construct(private readonly ChatCatalogDigitalInventoryService $inventory) {}

    public function index(): View
    {
        $workspace = $this->currentWorkspace();
        abort_unless(
            app(ChatCatalogAccess::class)->canViewDigitalInventory(request()->user(), $workspace),
            403,
        );

        return view('workspace.chat-catalog.digital-inventory', [
            'pools' => ChatCatalogDigitalInventoryPool::query()
                ->with(['item:id,name', 'variant:id,name'])
                ->withCount('availableUnits')
                ->orderByDesc('id')
                ->paginate(30),
            'digitalItems' => ChatCatalogItem::query()
                ->where('classification', 'digital')
                ->with('variants:id,chat_catalog_item_id,name')
                ->orderBy('name')
                ->get(['id', 'name']),
        ]);
    }

    public function storePool(StoreChatCatalogDigitalPoolRequest $request): RedirectResponse
    {
        $workspace = $this->currentWorkspace();
        abort_unless(
            app(ChatCatalogAccess::class)->canManageDigitalInventory($request->user(), $workspace),
            403,
        );
        $data = $request->validated();
        $item = ChatCatalogItem::query()->findOrFail($data['chat_catalog_item_id']);
        $variant = isset($data['chat_catalog_item_variant_id'])
            ? ChatCatalogItemVariant::query()->findOrFail($data['chat_catalog_item_variant_id'])
            : null;
        if ($variant && (int) $variant->chat_catalog_item_id !== (int) $item->id) {
            return back()->withErrors(['chat_catalog_item_variant_id' => 'النسخة لا تنتمي إلى الصنف المختار.']);
        }

        try {
            $this->inventory->createPool(
                $workspace,
                $item,
                $data['name'],
                $variant,
                (int) ($data['low_stock_threshold'] ?? 5),
                (int) ($data['reservation_timeout_seconds'] ?? 900),
            );
        } catch (InvalidArgumentException $exception) {
            return back()->withErrors(['chat_catalog_item_id' => $exception->getMessage()]);
        }

        return back()->with('success', 'تم إنشاء حوض المخزون الرقمي.');
    }

    public function import(StoreChatCatalogDigitalUnitImportRequest $request, ChatCatalogDigitalInventoryPool $pool): RedirectResponse
    {
        $workspace = $this->currentWorkspace();
        abort_unless(
            app(ChatCatalogAccess::class)->canManageDigitalInventory($request->user(), $workspace),
            403,
        );
        $this->assertSameWorkspace($pool->workspace_id);
        $data = $request->validated();

        try {
            $type = ChatCatalogDigitalUnitType::from($data['unit_type']);
            if ($type === ChatCatalogDigitalUnitType::File) {
                $this->inventory->importPrivateFileUnit(
                    $pool,
                    $data['file_asset'] ?? [],
                    isset($data['expires_at']) ? new \DateTimeImmutable($data['expires_at']) : null,
                );

                return back()->with('success', 'تم تسجيل الأصل الخاص.');
            }

            $summary = $this->inventory->bulkImportSecrets(
                $pool,
                $type,
                $data['secrets'] ?? [],
                isset($data['expires_at']) ? new \DateTimeImmutable($data['expires_at']) : null,
            );
        } catch (InvalidArgumentException $exception) {
            return back()->withErrors(['secrets' => $exception->getMessage()]);
        }

        return back()->with('success', "تم استيراد {$summary['imported']} وحدة وتجاوز {$summary['skipped_duplicates']} مكررة.");
    }
}
