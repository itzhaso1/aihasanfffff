<?php

namespace App\Http\Controllers\Workspace\Finance;

use App\Models\Finance\FinancePriceList;
use App\Models\Finance\FinancePriceListItem;
use App\Models\Product;
use App\Services\Finance\PriceListService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class PriceListController extends FinanceBaseController
{
    public function __construct(
        private readonly PriceListService $priceListService,
    ) {}

    public function index(Request $request): View
    {
        $this->authorizeFinance($request, 'finance.price_lists.view');
        $workspace = $this->currentWorkspace();

        $filters = [
            'search' => trim((string) $request->string('search', '')),
            'status' => trim((string) $request->string('status', '')),
        ];

        $lists = FinancePriceList::query()
            ->withCount('items')
            ->when($filters['search'] !== '', function ($query) use ($filters): void {
                $query->where(function ($inner) use ($filters): void {
                    $inner->where('name', 'like', '%'.$filters['search'].'%')
                        ->orWhere('code', 'like', '%'.$filters['search'].'%');
                });
            })
            ->when($filters['status'] !== '', fn ($query) => $query->where('status', $filters['status']))
            ->latest('id')
            ->paginate(15)
            ->withQueryString();

        $selectedList = null;
        if ($request->filled('price_list_id')) {
            $selectedList = FinancePriceList::query()
                ->with(['items.product'])
                ->whereKey($request->integer('price_list_id'))
                ->first();
        } elseif ($lists->count() > 0) {
            $selectedList = FinancePriceList::query()
                ->with(['items.product'])
                ->whereKey($lists->first()->id)
                ->first();
        }

        return view('workspace.finance.modules.price-lists', [
            'priceLists' => $lists,
            'selectedList' => $selectedList,
            'products' => Product::query()->orderBy('name')->get(['id', 'name', 'sku', 'price']),
            'workspaceCurrency' => 'SAR',
            'filters' => $filters,
            'workspace' => $workspace,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorizeFinance($request, 'finance.price_lists.manage');
        $workspace = $this->currentWorkspace();

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('finance_price_lists', 'name')->where(fn ($query) => $query->where('workspace_id', $workspace->id)->whereNull('deleted_at'))],
            'code' => ['nullable', 'string', 'max:64', Rule::unique('finance_price_lists', 'code')->where(fn ($query) => $query->where('workspace_id', $workspace->id)->whereNull('deleted_at'))],
            'currency' => ['required', 'string', 'size:3'],
            'effective_from' => ['nullable', 'date'],
            'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'],
            'notes' => ['nullable', 'string'],
        ]);

        $priceList = $this->priceListService->create($workspace, $validated, (int) $request->user()?->id);

        return redirect()
            ->route('workspace.finance.price-lists.index', ['price_list_id' => $priceList->id])
            ->with('success', 'تم إنشاء قائمة الأسعار بنجاح.');
    }

    public function update(Request $request, FinancePriceList $priceList): RedirectResponse
    {
        $this->authorizeFinance($request, 'finance.price_lists.manage');
        $this->assertSameWorkspace($priceList->workspace_id);

        $workspace = $this->currentWorkspace();
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('finance_price_lists', 'name')->ignore($priceList->id)->where(fn ($query) => $query->where('workspace_id', $workspace->id)->whereNull('deleted_at'))],
            'code' => ['nullable', 'string', 'max:64', Rule::unique('finance_price_lists', 'code')->ignore($priceList->id)->where(fn ($query) => $query->where('workspace_id', $workspace->id)->whereNull('deleted_at'))],
            'currency' => ['required', 'string', 'size:3'],
            'effective_from' => ['nullable', 'date'],
            'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'],
            'notes' => ['nullable', 'string'],
        ]);

        try {
            $this->priceListService->update($priceList, $validated);
        } catch (\RuntimeException $exception) {
            return back()->withInput()->with('error', $exception->getMessage());
        }

        return redirect()
            ->route('workspace.finance.price-lists.index', ['price_list_id' => $priceList->id])
            ->with('success', 'تم تحديث قائمة الأسعار.');
    }

    public function addItem(Request $request, FinancePriceList $priceList): RedirectResponse
    {
        $this->authorizeFinance($request, 'finance.price_lists.manage');

        $validated = $request->validate([
            'product_id' => ['nullable', 'integer'],
            'product_name' => ['nullable', 'string', 'max:255'],
            'sku' => ['nullable', 'string', 'max:64'],
            'min_quantity' => ['nullable', 'numeric', 'gt:0'],
            'price' => ['required', 'numeric', 'gt:0'],
            'tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        try {
            $this->priceListService->addItem($priceList, $validated);
        } catch (\RuntimeException $exception) {
            return back()->withInput()->with('error', $exception->getMessage());
        }

        return redirect()
            ->route('workspace.finance.price-lists.index', ['price_list_id' => $priceList->id])
            ->with('success', 'تمت إضافة عنصر التسعير.');
    }

    public function updateItem(Request $request, FinancePriceListItem $item): RedirectResponse
    {
        $this->authorizeFinance($request, 'finance.price_lists.manage');
        $validated = $request->validate([
            'product_name' => ['required', 'string', 'max:255'],
            'sku' => ['nullable', 'string', 'max:64'],
            'min_quantity' => ['nullable', 'numeric', 'gt:0'],
            'price' => ['required', 'numeric', 'gt:0'],
            'tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        try {
            $this->priceListService->updateItem($item, $validated);
        } catch (\RuntimeException $exception) {
            return back()->withInput()->with('error', $exception->getMessage());
        }

        return redirect()
            ->route('workspace.finance.price-lists.index', ['price_list_id' => $item->price_list_id])
            ->with('success', 'تم تحديث عنصر التسعير.');
    }

    public function deleteItem(FinancePriceListItem $item): RedirectResponse
    {
        $this->authorizeFinance(request(), 'finance.price_lists.manage');
        $priceListId = $item->price_list_id;

        try {
            $this->priceListService->deleteItem($item);
        } catch (\RuntimeException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return redirect()
            ->route('workspace.finance.price-lists.index', ['price_list_id' => $priceListId])
            ->with('success', 'تم حذف عنصر التسعير.');
    }

    public function approve(FinancePriceList $priceList): RedirectResponse
    {
        $this->authorizeFinance(request(), 'finance.price_lists.manage');
        try {
            $this->priceListService->approve($priceList, (int) request()->user()?->id);
        } catch (\RuntimeException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', 'تم اعتماد قائمة الأسعار.');
    }

    public function markDraft(FinancePriceList $priceList): RedirectResponse
    {
        $this->authorizeFinance(request(), 'finance.price_lists.manage');
        try {
            $this->priceListService->markDraft($priceList);
        } catch (\RuntimeException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', 'تم تحويل قائمة الأسعار إلى مسودة.');
    }

    public function cancel(FinancePriceList $priceList): RedirectResponse
    {
        $this->authorizeFinance(request(), 'finance.price_lists.manage');
        $this->priceListService->cancel($priceList);

        return back()->with('success', 'تم إلغاء قائمة الأسعار.');
    }
}
