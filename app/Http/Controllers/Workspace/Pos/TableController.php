<?php

namespace App\Http\Controllers\Workspace\Pos;

use App\Http\Requests\Pos\StoreDiningTableRequest;
use App\Http\Requests\Pos\UpdateDiningTableRequest;
use App\Models\DiningTable;
use App\Models\Order;
use App\Models\PosMenuItem;
use App\Models\TableSession;
use App\Services\Pos\PosOrderStatsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class TableController extends PosBaseController
{
    public function __construct(
        private readonly PosOrderStatsService $posOrderStatsService,
    ) {}

    public function index(Request $request): View
    {
        $this->authorizePos($request, 'tables.manage');

        $tables = $this->tablesQuery()->paginate(30);
        $tablesPayload = $this->buildTablesPayload($tables->getCollection());

        return view('workspace.pos.tables.index', [
            'tables' => $tables,
            'tablesPayload' => $tablesPayload,
            'liveBoardUrl' => route('workspace.pos.tables.live'),
            'orderChannelStats' => $this->posOrderStatsService->channelCounts(),
            'posStatuses' => $this->posStatusLabels(),
        ]);
    }

    /**
     * Live snapshot for the tables board (polling fallback when Reverb is offline).
     */
    public function liveBoard(Request $request): \Illuminate\Http\JsonResponse
    {
        $this->authorizePos($request, 'tables.manage');

        $tables = $this->tablesQuery()->limit(100)->get();

        return response()->json([
            'tables' => $this->buildTablesPayload($tables),
            'generated_at' => now()->toIso8601String(),
        ]);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Builder<\App\Models\DiningTable>
     */
    private function tablesQuery()
    {
        return DiningTable::query()
            ->with([
                'sessions' => fn ($query) => $query
                    ->where('status', 'open')
                    ->latest('id')
                    ->limit(1)
                    ->with([
                        'orders' => fn ($orders) => $orders
                            ->whereIn('source', ['pos', 'qr_menu'])
                            ->where('pos_status', '!=', 'cancelled')
                            ->with(['items', 'customer:id,name'])
                            ->latest('id'),
                    ]),
            ])
            ->withCount([
                'orders as orders_count' => fn ($query) => $query->whereIn('source', ['pos', 'qr_menu']),
                'orders as open_orders_count' => fn ($query) => $query
                    ->whereIn('source', ['pos', 'qr_menu'])
                    ->whereIn('pos_status', ['new', 'accepted', 'preparing', 'ready', 'delivered']),
            ])
            ->orderBy('name');
    }

    /**
     * @param  \Illuminate\Support\Collection<int, DiningTable>  $tables
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    private function buildTablesPayload($tables)
    {
        $workspace = $this->currentWorkspace();

        return $tables->map(function (DiningTable $table) use ($workspace) {
            $openSession = $table->sessions->first();
            $orders = $openSession?->orders ?? collect();
            $lines = $orders->flatMap(fn ($order) => $order->items->map(fn ($item) => [
                'name' => $item->product_name ?? $item->variant_name ?? 'صنف',
                'quantity' => (int) $item->quantity,
                'total' => (float) ($item->total_amount ?? 0),
            ]));

            return [
                'id' => $table->id,
                'name' => $table->name,
                'status' => $table->status,
                'open_orders_count' => (int) $table->open_orders_count,
                'orders_count' => (int) $table->orders_count,
                'opened_at' => optional($openSession?->opened_at)?->toIso8601String(),
                'session_id' => $openSession?->id,
                'show_url' => route('workspace.pos.tables.show', $table),
                'qr_regen_url' => route('workspace.pos.tables.qr.regenerate', $table),
                'menu_url' => route('menu.table', ['workspace' => $workspace->slug, 'token' => $table->qr_token]),
                'customer_name' => optional($orders->first()?->customer)->name,
                'lines' => $lines->values(),
                'total' => (float) $lines->sum('total'),
            ];
        })->values();
    }

    public function show(Request $request, DiningTable $table): View
    {
        $this->authorizePos($request, 'tables.manage');
        $this->assertSameWorkspace($table->workspace_id);
        $this->authorize('view', $table);

        $table->load(['sessions' => fn ($query) => $query->latest('id')->limit(20)]);

        $currentSession = $table->sessions->firstWhere('status', 'open');
        $sessionOrders = collect();
        if ($currentSession) {
            $sessionOrders = Order::query()
                ->where('dining_table_id', $table->id)
                ->where('table_session_id', $currentSession->id)
                ->whereIn('source', ['pos', 'qr_menu'])
                ->with(['items', 'payments', 'posCashierInvoice'])
                ->latest('id')
                ->get();
        }

        $menuItems = PosMenuItem::query()
            ->with('category:id,name')
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id', 'pos_item_category_id', 'name', 'item_type', 'size_label', 'description', 'price', 'currency', 'image_path']);

        $otherTables = DiningTable::query()
            ->whereKeyNot($table->id)
            ->orderBy('name')
            ->get(['id', 'name', 'status']);

        return view('workspace.pos.tables.show', [
            'table' => $table,
            'currentSession' => $currentSession,
            'sessionOrders' => $sessionOrders,
            'menuItems' => $menuItems,
            'otherTables' => $otherTables,
            'posStatuses' => $this->posStatusLabels(),
            'operationalLocked' => true,
        ]);
    }

    public function store(StoreDiningTableRequest $request): RedirectResponse
    {
        $this->authorizePos($request, 'tables.manage');

        $workspace = $this->currentWorkspace();
        $validated = $request->validated();
        $request->validate([
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('dining_tables', 'name')->where(fn ($query) => $query->where('workspace_id', $workspace->id)),
            ],
        ]);

        DiningTable::query()->create([
            'name' => $validated['name'],
            'status' => 'available',
            'qr_token' => Str::random(48),
        ]);

        return back()->with('success', 'تم إنشاء الطاولة بنجاح.');
    }

    public function update(UpdateDiningTableRequest $request, DiningTable $table): RedirectResponse
    {
        $this->authorizePos($request, 'tables.manage');
        $this->authorize('update', $table);

        $workspace = $this->currentWorkspace();
        $validated = $request->validated();

        $request->validate([
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('dining_tables', 'name')
                    ->where(fn ($query) => $query->where('workspace_id', $workspace->id))
                    ->ignore($table->id),
            ],
        ]);

        $table->update([
            'name' => $validated['name'],
            'status' => $validated['status'] ?? $table->status,
        ]);

        return back()->with('success', 'تم تحديث بيانات الطاولة.');
    }

    public function openSession(Request $request, DiningTable $table): RedirectResponse
    {
        $this->abortWebPosOperation();
    }

    public function closeSession(Request $request, DiningTable $table, TableSession $session): RedirectResponse
    {
        $this->abortWebPosOperation();
    }

    public function cancelSession(Request $request, DiningTable $table, TableSession $session): RedirectResponse
    {
        $this->abortWebPosOperation();
    }

    public function applyDiscount(Request $request, DiningTable $table, TableSession $session): RedirectResponse
    {
        $this->abortWebPosOperation();
    }

    public function addOrder(Request $request, DiningTable $table): RedirectResponse
    {
        $this->abortWebPosOperation();
    }

    public function transferSession(Request $request, DiningTable $table, TableSession $session): RedirectResponse
    {
        $this->abortWebPosOperation();
    }

    public function mergeSession(Request $request, DiningTable $table, TableSession $session): RedirectResponse
    {
        $this->abortWebPosOperation();
    }

    public function splitSession(Request $request, DiningTable $table, TableSession $session): RedirectResponse
    {
        $this->abortWebPosOperation();
    }

    public function updateSessionNote(Request $request, DiningTable $table, TableSession $session): RedirectResponse
    {
        $this->abortWebPosOperation();
    }

    public function regenerateQr(Request $request, DiningTable $table): RedirectResponse
    {
        $this->authorizePos($request, 'tables.manage');
        $this->authorize('update', $table);

        $table->update([
            'qr_token' => Str::random(48),
        ]);

        return back()->with('success', 'تم إنشاء رمز QR جديد للطاولة.');
    }

    /**
     * @return array<string,string>
     */
    private function posStatusLabels(): array
    {
        return [
            'new' => 'جديد',
            'accepted' => 'تم القبول',
            'preparing' => 'قيد التحضير',
            'ready' => 'جاهز',
            'delivered' => 'تم التسليم',
            'completed' => 'مكتمل',
            'cancelled' => 'ملغي',
        ];
    }
}
