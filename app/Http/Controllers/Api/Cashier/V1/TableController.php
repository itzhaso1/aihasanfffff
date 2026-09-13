<?php

namespace App\Http\Controllers\Api\Cashier\V1;

use App\Http\Controllers\Api\Cashier\CashierController;
use App\Http\Controllers\Api\Cashier\Concerns\AuthorizesCashier;
use App\Http\Controllers\Api\Cashier\Concerns\ResolvesCashierWorkspace;
use App\Http\Resources\Cashier\OrderResource;
use App\Models\DiningTable;
use App\Models\Order;
use App\Models\TableSession;
use App\Services\Feature\FeatureAccessService;
use App\Services\Pos\PosOrderService;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use RuntimeException;

class TableController extends CashierController
{
    use AuthorizesCashier;
    use ResolvesCashierWorkspace;

    public function __construct(
        private readonly WorkspaceContext $workspaceContext,
        private readonly FeatureAccessService $featureAccessService,
        private readonly PosOrderService $posOrderService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $workspace = $this->requireWorkspace($this->workspaceContext);
        $this->authorizeCashier($request, $workspace, 'tables.manage');
        $this->ensurePos($workspace);

        $query = DiningTable::query()
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
            ->orderBy('name');

        $wantsPagination = $request->query->has('page') || $request->query->has('per_page');
        if ($wantsPagination) {
            $perPage = min(100, max(1, (int) $request->query('per_page', 100)));
            $page = max(1, (int) $request->query('page', 1));
            $tables = $query->paginate($perPage, ['*'], 'page', $page);

            return $this->ok([
                'tables' => $tables->getCollection()->map(fn (DiningTable $table) => $this->tablePayload($table, $workspace))->values(),
                'generated_at' => now()->toIso8601String(),
            ], meta: [
                'current_page' => $tables->currentPage(),
                'per_page' => $tables->perPage(),
                'total' => $tables->total(),
                'last_page' => $tables->lastPage(),
            ]);
        }

        $tables = $query->limit(100)->get();

        return $this->ok([
            'tables' => $tables->map(fn (DiningTable $table) => $this->tablePayload($table, $workspace))->values(),
            'generated_at' => now()->toIso8601String(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $workspace = $this->requireWorkspace($this->workspaceContext);
        $this->authorizeCashier($request, $workspace, 'tables.manage');
        $this->ensurePos($workspace);

        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('dining_tables', 'name')->where(fn ($query) => $query->where('workspace_id', $workspace->id)),
            ],
        ]);

        $table = DiningTable::query()->create([
            'name' => $validated['name'],
            'status' => 'available',
            'qr_token' => Str::random(48),
        ]);

        return $this->ok([
            'id' => $table->id,
            'name' => $table->name,
            'status' => $table->status,
            'qr_token' => $table->qr_token,
        ], message: 'تم إنشاء الطاولة بنجاح.', status: 201);
    }

    public function show(Request $request, DiningTable $table): JsonResponse
    {
        $workspace = $this->requireWorkspace($this->workspaceContext);
        $this->authorizeCashier($request, $workspace, 'tables.manage');
        $this->ensurePos($workspace);

        $table->load([
            'sessions' => fn ($query) => $query->where('status', 'open')->latest('id')->limit(1)->with([
                'orders' => fn ($orders) => $orders
                    ->whereIn('source', ['pos', 'qr_menu'])
                    ->where('pos_status', '!=', 'cancelled')
                    ->with(['items', 'customer:id,name,phone'])
                    ->latest('id'),
            ]),
        ]);

        $payload = $this->tablePayload($table, $workspace, detailed: true);
        $payload['sessions'] = $this->sessionsHistory($table);

        return $this->ok($payload);
    }

    /**
     * Latest sessions for a table (Web show loads latest 20).
     */
    public function sessions(Request $request, DiningTable $table): JsonResponse
    {
        $workspace = $this->requireWorkspace($this->workspaceContext);
        $this->authorizeCashier($request, $workspace, 'tables.manage');
        $this->ensurePos($workspace);

        return $this->ok([
            'table_id' => $table->id,
            'sessions' => $this->sessionsHistory($table),
        ]);
    }

    public function openSession(Request $request, DiningTable $table): JsonResponse
    {
        $workspace = $this->requireWorkspace($this->workspaceContext);
        $this->authorizeCashier($request, $workspace, 'tables.manage');
        $this->ensurePos($workspace);

        $session = $this->posOrderService->openSession($table);

        return $this->ok([
            'session_id' => $session->id,
            'table_id' => $table->id,
            'status' => $session->status,
            'opened_at' => optional($session->opened_at)?->toIso8601String(),
        ], message: 'تم فتح جلسة الطاولة.');
    }

    public function closeSession(Request $request, DiningTable $table, TableSession $session): JsonResponse
    {
        $workspace = $this->requireWorkspace($this->workspaceContext);
        $user = $this->authorizeCashier($request, $workspace, 'tables.manage');
        $this->ensureSession($table, $session);

        $validated = $request->validate([
            'payment_method' => ['nullable', 'string', 'in:cash,card,cashier,pay_now,pay_later,transfer'],
        ]);

        try {
            $invoice = $this->posOrderService->closeSession(
                $session,
                (int) $user->id,
                $validated['payment_method'] ?? null,
            );
        } catch (RuntimeException $exception) {
            return $this->fail($exception->getMessage(), 422);
        }

        return $this->ok([
            'invoice' => $invoice ? [
                'id' => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
                'total_amount' => (float) $invoice->total_amount,
                'subtotal' => (float) $invoice->subtotal,
                'discount_amount' => (float) $invoice->discount_amount,
                'currency' => $invoice->currency,
                'payment_method' => $validated['payment_method'] ?? null,
            ] : null,
        ], message: $invoice ? 'تم إغلاق الجلسة وإصدار فاتورة.' : 'تم إغلاق الجلسة.');
    }

    public function cancelSession(Request $request, DiningTable $table, TableSession $session): JsonResponse
    {
        $workspace = $this->requireWorkspace($this->workspaceContext);
        $this->authorizeCashier($request, $workspace, 'tables.manage');
        $this->ensureSession($table, $session);

        try {
            $this->posOrderService->cancelSession($session, $request->user());
        } catch (RuntimeException $exception) {
            return $this->fail($exception->getMessage(), 422);
        }

        return $this->ok(message: 'تم إلغاء جلسة الطاولة.');
    }

    public function transfer(Request $request, DiningTable $table, TableSession $session): JsonResponse
    {
        $workspace = $this->requireWorkspace($this->workspaceContext);
        $this->authorizeCashier($request, $workspace, 'tables.manage');
        $this->ensureSession($table, $session);

        $validated = $request->validate([
            'target_table_id' => [
                'required',
                'integer',
                Rule::exists('dining_tables', 'id')->where(fn ($q) => $q->where('workspace_id', $workspace->id)),
            ],
        ]);

        $target = DiningTable::query()->findOrFail((int) $validated['target_table_id']);

        try {
            $this->posOrderService->transferSession($session, $target);
        } catch (RuntimeException $exception) {
            return $this->fail($exception->getMessage(), 422);
        }

        return $this->ok(message: 'تم نقل الجلسة إلى الطاولة المحددة.');
    }

    public function merge(Request $request, DiningTable $table, TableSession $session): JsonResponse
    {
        $workspace = $this->requireWorkspace($this->workspaceContext);
        $this->authorizeCashier($request, $workspace, 'tables.manage');
        $this->ensureSession($table, $session);

        $validated = $request->validate([
            'target_table_id' => [
                'required',
                'integer',
                Rule::exists('dining_tables', 'id')->where(fn ($q) => $q->where('workspace_id', $workspace->id)),
            ],
        ]);

        $target = DiningTable::query()->findOrFail((int) $validated['target_table_id']);

        try {
            $this->posOrderService->mergeSessions($session, $target);
        } catch (RuntimeException $exception) {
            return $this->fail($exception->getMessage(), 422);
        }

        return $this->ok(message: 'تم دمج الجلسات.');
    }

    public function split(Request $request, DiningTable $table, TableSession $session): JsonResponse
    {
        $workspace = $this->requireWorkspace($this->workspaceContext);
        // Web SoT: orders.manage + groups min:2
        $user = $this->authorizeCashier($request, $workspace, 'orders.manage');
        $this->ensureSession($table, $session);

        $validated = $request->validate([
            'groups' => ['required', 'array', 'min:2'],
            'groups.*.items' => ['required', 'array', 'min:1'],
            'groups.*.items.*.order_item_id' => ['required', 'integer'],
            'groups.*.items.*.quantity' => ['required', 'integer', 'min:0'],
        ]);

        try {
            $orders = $this->posOrderService->splitSessionByItems($session, $validated['groups'], $user);
        } catch (RuntimeException $exception) {
            return $this->fail($exception->getMessage(), 422);
        }

        return $this->ok([
            'orders' => OrderResource::collection($orders->load(['items'])),
        ], message: 'تم تقسيم الحساب.');
    }

    public function applyDiscount(Request $request, DiningTable $table, TableSession $session): JsonResponse
    {
        $workspace = $this->requireWorkspace($this->workspaceContext);
        // Web SoT: orders.manage (not tables.manage)
        $this->authorizeCashier($request, $workspace, 'orders.manage');
        $this->ensureSession($table, $session);

        $validated = $request->validate([
            'discount_amount' => ['required', 'numeric', 'min:0'],
        ]);

        try {
            $this->posOrderService->applySessionDiscount($session, (float) $validated['discount_amount']);
        } catch (RuntimeException $exception) {
            return $this->fail($exception->getMessage(), 422);
        }

        return $this->ok(message: 'تم تطبيق الخصم على الجلسة.');
    }

    public function update(Request $request, DiningTable $table): JsonResponse
    {
        $workspace = $this->requireWorkspace($this->workspaceContext);
        $this->authorizeCashier($request, $workspace, 'tables.manage');
        $this->ensurePos($workspace);

        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('dining_tables', 'name')
                    ->where(fn ($query) => $query->where('workspace_id', $workspace->id))
                    ->ignore($table->id),
            ],
            'status' => ['nullable', 'string', Rule::in(['available', 'occupied', 'reserved', 'cleaning', 'closed'])],
        ]);

        $table->update([
            'name' => $validated['name'],
            'status' => $validated['status'] ?? $table->status,
        ]);

        return $this->ok([
            'id' => $table->id,
            'name' => $table->name,
            'status' => $table->status,
            'qr_token' => $table->qr_token,
        ], message: 'تم تحديث بيانات الطاولة.');
    }

    public function regenerateQr(Request $request, DiningTable $table): JsonResponse
    {
        $workspace = $this->requireWorkspace($this->workspaceContext);
        $this->authorizeCashier($request, $workspace, 'tables.manage');
        $this->ensurePos($workspace);

        $table->update([
            'qr_token' => Str::random(48),
        ]);

        return $this->ok([
            'id' => $table->id,
            'qr_token' => $table->qr_token,
            'menu_url' => url('/menu/'.$workspace->slug.'/table/'.$table->qr_token),
        ], message: 'تم إنشاء رمز QR جديد للطاولة.');
    }

    public function updateNote(Request $request, DiningTable $table, TableSession $session): JsonResponse
    {
        $workspace = $this->requireWorkspace($this->workspaceContext);
        $this->authorizeCashier($request, $workspace, 'orders.manage');
        $this->ensureSession($table, $session);

        $validated = $request->validate([
            'notes' => ['required', 'string', 'max:2000'],
        ]);

        try {
            $this->posOrderService->applySessionNote($session, $validated['notes']);
        } catch (RuntimeException $exception) {
            return $this->fail($exception->getMessage(), 422);
        }

        return $this->ok(message: 'تم حفظ الملاحظة.');
    }

    /**
     * @return array<string, mixed>
     */
    private function tablePayload(DiningTable $table, \App\Models\Workspace $workspace, bool $detailed = false): array
    {
        $openSession = $table->sessions->first();
        $orders = $openSession?->orders ?? collect();
        $lines = $orders->flatMap(fn ($order) => $order->items->map(fn ($item) => [
            'id' => $item->id,
            'name' => $item->product_name ?? 'صنف',
            'quantity' => (int) $item->quantity,
            'unit_price' => (float) ($item->unit_price ?? 0),
            'total' => (float) ($item->total_amount ?? 0),
        ]));

        $billable = $orders->where('pos_status', '!=', 'cancelled');
        $payload = [
            'id' => $table->id,
            'name' => $table->name,
            'status' => $table->status,
            'session_id' => $openSession?->id,
            'session_status' => $openSession?->status,
            'opened_at' => optional($openSession?->opened_at)?->toIso8601String(),
            'customer_name' => optional($orders->first()?->customer)->name,
            // Align with Web withCount: active kitchen statuses only (not cancelled/completed).
            'open_orders_count' => $orders
                ->whereIn('pos_status', ['new', 'accepted', 'preparing', 'ready', 'delivered'])
                ->count(),
            'orders_count' => $billable->count(),
            'items_count' => (int) $lines->sum('quantity'),
            'lines' => $lines->values(),
            'subtotal' => (float) $billable->sum('subtotal'),
            'discount_amount' => (float) $billable->sum('discount_amount'),
            'tax_amount' => (float) $billable->sum('tax_amount'),
            'total' => (float) $billable->sum(fn ($o) => (float) $o->total_amount),
            'notes' => optional(
                $orders->first(fn ($order) => filled($order->notes))
            )?->notes
                ?? data_get($orders->first()?->metadata, 'session_note'),
            'menu_url' => url('/menu/'.$workspace->slug.'/table/'.$table->qr_token),
            'qr_token' => $table->qr_token,
        ];

        if ($detailed) {
            $payload['orders'] = OrderResource::collection($orders);
            $payload['session_open'] = $openSession !== null && $openSession->status === 'open';
        }

        return $payload;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function sessionsHistory(DiningTable $table, int $limit = 20): array
    {
        $sessions = TableSession::query()
            ->where('dining_table_id', $table->id)
            ->withCount([
                'orders as orders_count' => fn ($query) => $query
                    ->whereIn('source', ['pos', 'qr_menu'])
                    ->where('pos_status', '!=', 'cancelled'),
            ])
            ->latest('id')
            ->limit($limit)
            ->get();

        return $sessions->map(function (TableSession $session) {
            $billable = Order::query()
                ->where('table_session_id', $session->id)
                ->whereIn('source', ['pos', 'qr_menu'])
                ->where('pos_status', '!=', 'cancelled')
                ->get(['id', 'subtotal', 'discount_amount', 'tax_amount', 'total_amount', 'pos_cashier_invoice_id']);

            return [
                'id' => $session->id,
                'status' => $session->status,
                'opened_at' => optional($session->opened_at)?->toIso8601String(),
                'closed_at' => optional($session->closed_at)?->toIso8601String(),
                'orders_count' => (int) ($session->orders_count ?? $billable->count()),
                'subtotal' => (float) $billable->sum('subtotal'),
                'discount_amount' => (float) $billable->sum('discount_amount'),
                'tax_amount' => (float) $billable->sum('tax_amount'),
                'total' => (float) $billable->sum(fn ($order) => (float) $order->total_amount),
                'invoiced' => $billable->contains(fn ($order) => $order->pos_cashier_invoice_id !== null),
            ];
        })->values()->all();
    }

    private function ensurePos(\App\Models\Workspace $workspace): void
    {
        if (! $this->featureAccessService->workspaceHasFeature($workspace, 'pos')) {
            throw new HttpResponseException(
                $this->fail('الكاشير غير متاح في باقتك الحالية', 403, meta: [
                    'pos_enabled' => false,
                    'plans_url' => url('/workspace/billing'),
                ])
            );
        }
    }

    private function ensureSession(DiningTable $table, TableSession $session): void
    {
        if ((int) $session->dining_table_id !== (int) $table->id) {
            throw new HttpResponseException($this->fail('الجلسة غير مرتبطة بهذه الطاولة.', 404));
        }
    }
}
