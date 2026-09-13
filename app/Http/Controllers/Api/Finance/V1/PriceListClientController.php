<?php

namespace App\Http\Controllers\Api\Finance\V1;

use App\Http\Controllers\Api\Finance\Concerns\HandlesFinanceClient;
use App\Http\Controllers\Api\Finance\FinanceApiController;
use App\Models\Finance\FinancePriceList;
use App\Models\Finance\FinancePriceListItem;
use App\Services\Finance\Api\FinanceClientPresenter;
use App\Services\Finance\PriceListService;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PriceListClientController extends FinanceApiController
{
    use HandlesFinanceClient;

    public function __construct(
        private readonly WorkspaceContext $workspaceContext,
        private readonly PriceListService $priceListService,
        private readonly FinanceClientPresenter $presenter,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $workspace = $this->clientWorkspace($this->workspaceContext);
        $this->clientActor($request, $workspace, 'finance.price_lists.view');

        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'string', 'max:40'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $page = FinancePriceList::query()
            ->withCount('items')
            ->when($validated['search'] ?? null, function ($query, $search): void {
                $query->where(function ($inner) use ($search): void {
                    $inner->where('name', 'like', '%'.$search.'%')
                        ->orWhere('code', 'like', '%'.$search.'%');
                });
            })
            ->when($validated['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->latest('id')
            ->paginate((int) ($validated['per_page'] ?? 25));

        return $this->ok(
            $page->getCollection()->map(fn (FinancePriceList $list) => $this->presenter->priceList($list))->values()->all(),
            meta: $this->pageMeta($page),
        );
    }

    public function show(Request $request, FinancePriceList $priceList): JsonResponse
    {
        $this->clientActor($request, $this->clientWorkspace($this->workspaceContext), 'finance.price_lists.view');
        $priceList->load('items');

        return $this->ok($this->presenter->priceList($priceList, true));
    }

    public function store(Request $request): JsonResponse
    {
        $workspace = $this->clientWorkspace($this->workspaceContext);
        $this->clientActor($request, $workspace, 'finance.price_lists.manage');
        $validated = $request->validate($this->listRules((int) $workspace->id));
        $list = $this->priceListService->create($workspace, $validated, (int) $request->user()?->id);

        return $this->ok($this->presenter->priceList($list->loadCount('items')), message: 'تم إنشاء قائمة الأسعار.', status: 201);
    }

    public function update(Request $request, FinancePriceList $priceList): JsonResponse
    {
        $workspace = $this->clientWorkspace($this->workspaceContext);
        $this->clientActor($request, $workspace, 'finance.price_lists.manage');
        $validated = $request->validate($this->listRules((int) $workspace->id, (int) $priceList->id));
        $updated = $this->runFinanceDomain(fn () => $this->priceListService->update($priceList, $validated));

        return $this->ok($this->presenter->priceList($updated->load('items'), true), message: 'تم تحديث قائمة الأسعار.');
    }

    public function addItem(Request $request, FinancePriceList $priceList): JsonResponse
    {
        $this->clientActor($request, $this->clientWorkspace($this->workspaceContext), 'finance.price_lists.manage');
        $validated = $request->validate([
            'product_id' => ['nullable', 'integer'],
            'product_name' => ['nullable', 'string', 'max:255'],
            'sku' => ['nullable', 'string', 'max:64'],
            'min_quantity' => ['nullable', 'numeric', 'gt:0'],
            'price' => ['required', 'numeric', 'gt:0'],
            'tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'is_active' => ['nullable', 'boolean'],
        ]);
        $item = $this->runFinanceDomain(fn () => $this->priceListService->addItem($priceList, $validated));

        return $this->ok($this->presenter->priceListItem($item), message: 'تمت إضافة عنصر التسعير.', status: 201);
    }

    public function updateItem(Request $request, FinancePriceListItem $item): JsonResponse
    {
        $this->clientActor($request, $this->clientWorkspace($this->workspaceContext), 'finance.price_lists.manage');
        $validated = $request->validate([
            'product_name' => ['required', 'string', 'max:255'],
            'sku' => ['nullable', 'string', 'max:64'],
            'min_quantity' => ['nullable', 'numeric', 'gt:0'],
            'price' => ['required', 'numeric', 'gt:0'],
            'tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'is_active' => ['nullable', 'boolean'],
        ]);
        $updated = $this->runFinanceDomain(fn () => $this->priceListService->updateItem($item, $validated));

        return $this->ok($this->presenter->priceListItem($updated), message: 'تم تحديث عنصر التسعير.');
    }

    public function deleteItem(Request $request, FinancePriceListItem $item): JsonResponse
    {
        $this->clientActor($request, $this->clientWorkspace($this->workspaceContext), 'finance.price_lists.manage');
        $this->runFinanceDomain(fn () => $this->priceListService->deleteItem($item));

        return $this->ok(message: 'تم حذف عنصر التسعير.');
    }

    public function approve(Request $request, FinancePriceList $priceList): JsonResponse
    {
        $this->clientActor($request, $this->clientWorkspace($this->workspaceContext), 'finance.price_lists.manage');
        $updated = $this->runFinanceDomain(fn () => $this->priceListService->approve($priceList, (int) $request->user()?->id));

        return $this->ok($this->presenter->priceList($updated->load('items'), true), message: 'تم اعتماد قائمة الأسعار.');
    }

    public function markDraft(Request $request, FinancePriceList $priceList): JsonResponse
    {
        $this->clientActor($request, $this->clientWorkspace($this->workspaceContext), 'finance.price_lists.manage');
        $updated = $this->runFinanceDomain(fn () => $this->priceListService->markDraft($priceList));

        return $this->ok($this->presenter->priceList($updated->load('items'), true), message: 'تم تحويل قائمة الأسعار إلى مسودة.');
    }

    public function cancel(Request $request, FinancePriceList $priceList): JsonResponse
    {
        $this->clientActor($request, $this->clientWorkspace($this->workspaceContext), 'finance.price_lists.manage');
        $updated = $this->priceListService->cancel($priceList);

        return $this->ok($this->presenter->priceList($updated->load('items'), true), message: 'تم إلغاء قائمة الأسعار.');
    }

    /**
     * @return array<string, mixed>
     */
    private function listRules(int $workspaceId, ?int $ignoreId = null): array
    {
        return [
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('finance_price_lists', 'name')
                    ->where(fn ($query) => $query->where('workspace_id', $workspaceId)->whereNull('deleted_at'))
                    ->ignore($ignoreId),
            ],
            'code' => [
                'nullable',
                'string',
                'max:64',
                Rule::unique('finance_price_lists', 'code')
                    ->where(fn ($query) => $query->where('workspace_id', $workspaceId)->whereNull('deleted_at'))
                    ->ignore($ignoreId),
            ],
            'currency' => ['required', 'string', 'size:3'],
            'effective_from' => ['nullable', 'date'],
            'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
