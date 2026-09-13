<?php

namespace App\Http\Controllers\Api\Cashier\V1;

use App\Http\Controllers\Api\Cashier\CashierController;
use App\Http\Controllers\Api\Cashier\Concerns\AuthorizesCashier;
use App\Http\Controllers\Api\Cashier\Concerns\ResolvesCashierWorkspace;
use App\Models\Customer;
use App\Services\Feature\FeatureAccessService;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CustomerController extends CashierController
{
    use AuthorizesCashier;
    use ResolvesCashierWorkspace;

    public function __construct(
        private readonly WorkspaceContext $workspaceContext,
        private readonly FeatureAccessService $featureAccessService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $workspace = $this->requireWorkspace($this->workspaceContext);
        $this->authorizeCashier($request, $workspace);
        $this->ensurePos($workspace);

        $q = trim((string) $request->query('q', ''));
        $query = Customer::query()->latest('id');

        if ($q !== '') {
            $query->where(function ($inner) use ($q): void {
                $inner->where('name', 'like', '%'.$q.'%')
                    ->orWhere('phone', 'like', '%'.$q.'%');
            });
        }

        $customers = $query->limit(30)->get(['id', 'name', 'phone', 'client_reference']);

        return $this->ok([
            'customers' => $customers->map(fn (Customer $customer) => [
                'id' => $customer->id,
                'name' => $customer->name,
                'phone' => $customer->phone,
                'client_reference' => $customer->client_reference,
            ])->values(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $workspace = $this->requireWorkspace($this->workspaceContext);
        $this->authorizeCashier($request, $workspace);
        $this->ensurePos($workspace);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:32'],
            'client_reference' => ['nullable', 'string', 'max:120'],
        ]);

        $clientRef = isset($validated['client_reference'])
            ? trim((string) $validated['client_reference'])
            : '';
        if ($clientRef !== '') {
            $existingByRef = Customer::query()
                ->where('client_reference', $clientRef)
                ->first();
            if ($existingByRef) {
                return $this->ok([
                    'id' => $existingByRef->id,
                    'name' => $existingByRef->name,
                    'phone' => $existingByRef->phone,
                    'client_reference' => $existingByRef->client_reference,
                ], message: 'عميل موجود مسبقاً.');
            }
        }

        $phone = trim($validated['phone']);
        $existingByPhone = Customer::query()->where('phone', $phone)->first();
        if ($existingByPhone) {
            // Idempotent phone match — never create a duplicate contact.
            if ($clientRef !== '' && empty($existingByPhone->client_reference)) {
                $existingByPhone->client_reference = $clientRef;
                $existingByPhone->save();
            }

            return $this->ok([
                'id' => $existingByPhone->id,
                'name' => $existingByPhone->name,
                'phone' => $existingByPhone->phone,
                'client_reference' => $existingByPhone->client_reference,
            ], message: 'عميل موجود مسبقاً.');
        }

        $request->validate([
            'phone' => [
                Rule::unique('customers', 'phone')->where(fn ($q) => $q->where('workspace_id', $workspace->id)),
            ],
        ]);

        $customer = Customer::query()->create([
            'workspace_id' => $workspace->id,
            'name' => $validated['name'],
            'phone' => $phone,
            'client_reference' => $clientRef !== '' ? $clientRef : null,
            'orders_count' => 0,
            'total_purchases' => 0,
        ]);

        return $this->ok([
            'id' => $customer->id,
            'name' => $customer->name,
            'phone' => $customer->phone,
            'client_reference' => $customer->client_reference,
        ], message: 'تم إنشاء العميل.', status: 201);
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
}
