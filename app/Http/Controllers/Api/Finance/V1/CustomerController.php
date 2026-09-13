<?php

namespace App\Http\Controllers\Api\Finance\V1;

use App\Http\Controllers\Api\Finance\Concerns\HandlesFinanceClient;
use App\Http\Controllers\Api\Finance\FinanceApiController;
use App\Http\Requests\Customer\CustomerPayloadRules;
use App\Models\Contract\Contract;
use App\Models\Customer;
use App\Models\Finance\FinanceInvoice;
use App\Models\Finance\FinanceInvoicePayment;
use App\Models\Finance\FinanceQuote;
use App\Models\Finance\FinanceReceipt;
use App\Services\Customer\CustomerService;
use App\Services\Finance\Api\FinanceClientPresenter;
use App\Services\Finance\CustomerBalanceService;
use App\Services\Finance\FinanceBootstrapService;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CustomerController extends FinanceApiController
{
    use HandlesFinanceClient;

    public function __construct(
        private readonly WorkspaceContext $workspaceContext,
        private readonly CustomerService $customerService,
        private readonly CustomerBalanceService $customerBalanceService,
        private readonly FinanceBootstrapService $financeBootstrapService,
        private readonly FinanceClientPresenter $presenter,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $workspace = $this->clientWorkspace($this->workspaceContext);
        $this->clientActor($request, $workspace, 'finance.view');
        $this->financeBootstrapService->ensureWorkspaceFinanceSetup($workspace);

        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'party_type' => ['nullable', 'in:individual,company'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $page = Customer::query()
            ->when($validated['search'] ?? null, function ($query, $search): void {
                $query->where(function ($inner) use ($search): void {
                    $inner->where('name', 'like', '%'.$search.'%')
                        ->orWhere('email', 'like', '%'.$search.'%')
                        ->orWhere('phone', 'like', '%'.$search.'%')
                        ->orWhere('vat_number', 'like', '%'.$search.'%');
                });
            })
            ->when($validated['party_type'] ?? null, fn ($query, $type) => $query->where('party_type', $type))
            ->latest('id')
            ->paginate((int) ($validated['per_page'] ?? 25));

        $ids = $page->getCollection()->pluck('id')->map(fn ($id) => (int) $id)->all();
        $outstanding = $this->customerBalanceService->outstandingByCustomerIds((int) $workspace->id, $ids);

        return $this->ok(
            $page->getCollection()
                ->map(fn (Customer $customer) => $this->presenter->customer(
                    $customer,
                    $outstanding[(int) $customer->id] ?? 0.0,
                ))
                ->values()
                ->all(),
            meta: $this->pageMeta($page),
        );
    }

    public function show(Request $request, Customer $customer): JsonResponse
    {
        $workspace = $this->clientWorkspace($this->workspaceContext);
        $this->clientActor($request, $workspace, 'finance.view');

        $outstanding = $this->customerBalanceService->outstanding((int) $workspace->id, (int) $customer->id);

        $invoices = FinanceInvoice::query()
            ->where('type', 'sales')
            ->where('customer_id', $customer->id)
            ->with('customer')
            ->latest('id')
            ->limit(20)
            ->get();
        $quotes = FinanceQuote::query()->where('customer_id', $customer->id)->with('customer')->latest('id')->limit(20)->get();
        $payments = FinanceInvoicePayment::query()
            ->whereHas('invoice', fn ($query) => $query->where('customer_id', $customer->id)->where('type', 'sales'))
            ->with(['invoice.customer', 'receipt'])
            ->latest('id')
            ->limit(20)
            ->get();
        $receipts = FinanceReceipt::query()->where('customer_id', $customer->id)->with(['customer', 'invoice'])->latest('id')->limit(20)->get();
        $contracts = Contract::query()->where('customer_id', $customer->id)->with('customer')->latest('id')->limit(20)->get();

        return $this->ok($this->presenter->customer($customer, $outstanding, [
            'invoices' => $invoices->map(fn ($row) => $this->presenter->invoiceSummary($row))->values()->all(),
            'quotes' => $quotes->map(fn ($row) => $this->presenter->quoteSummary($row))->values()->all(),
            'payments' => $payments->map(fn ($row) => $this->presenter->payment($row))->values()->all(),
            'receipts' => $receipts->map(fn ($row) => $this->presenter->receiptSummary($row))->values()->all(),
            'contracts' => $contracts->map(fn ($row) => $this->presenter->contract($row))->values()->all(),
        ]));
    }

    public function store(Request $request): JsonResponse
    {
        $workspace = $this->clientWorkspace($this->workspaceContext);
        $this->clientActor($request, $workspace, 'customers.manage');

        $payload = $this->validatedCustomer($request);
        $payload['workspace_id'] = $workspace->id;
        $payload['party_type'] = $payload['party_type'] ?? Customer::PARTY_TYPE_INDIVIDUAL;
        if (! empty($payload['country_code'])) {
            $payload['country_code'] = strtoupper((string) $payload['country_code']);
        }

        $customer = $this->customerService->create($payload);
        $outstanding = $this->customerBalanceService->outstanding((int) $workspace->id, (int) $customer->id);

        return $this->ok($this->presenter->customer($customer, $outstanding), message: 'تم إنشاء العميل.', status: 201);
    }

    public function update(Request $request, Customer $customer): JsonResponse
    {
        $workspace = $this->clientWorkspace($this->workspaceContext);
        $this->clientActor($request, $workspace, 'customers.manage');

        $payload = $this->validatedCustomer($request, updating: true);
        if (! empty($payload['country_code'])) {
            $payload['country_code'] = strtoupper((string) $payload['country_code']);
        }
        unset($payload['balance']);

        $customer = $this->customerService->update($customer, $payload);
        $outstanding = $this->customerBalanceService->outstanding((int) $workspace->id, (int) $customer->id);

        return $this->ok($this->presenter->customer($customer, $outstanding), message: 'تم تحديث العميل.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedCustomer(Request $request, bool $updating = false): array
    {
        $name = $updating ? ['sometimes', 'string', 'max:255'] : ['required', 'string', 'max:255'];
        $phone = $updating ? ['sometimes', 'string', 'max:32'] : ['required', 'string', 'max:32'];

        return $request->validate(array_merge([
            'name' => $name,
            'phone' => $phone,
            'whatsapp' => ['nullable', 'string', 'max:32'],
            'email' => ['nullable', 'email', 'max:255'],
            'notes' => ['nullable', 'string'],
            'party_type' => ['nullable', Rule::in(['individual', 'company'])],
        ], CustomerPayloadRules::financial()));
    }
}
