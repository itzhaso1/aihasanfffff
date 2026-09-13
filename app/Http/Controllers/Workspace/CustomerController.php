<?php

namespace App\Http\Controllers\Workspace;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Workspace\Concerns\InteractsWithWorkspace;
use App\Http\Requests\Customer\StoreCustomerRequest;
use App\Http\Requests\Customer\UpdateCustomerRequest;
use App\Models\Customer;
use App\Models\CustomerGroup;
use App\Models\CustomerTag;
use App\Services\Customer\CustomerCrmService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CustomerController extends Controller
{
    use InteractsWithWorkspace;

    public function __construct(
        private readonly CustomerCrmService $crm,
    ) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Customer::class);

        $customers = Customer::query()
            ->when($request->string('search')->toString(), function ($query, $search): void {
                $query->where(function ($inner) use ($search): void {
                    $inner->where('name', 'like', '%'.$search.'%')
                        ->orWhere('phone', 'like', '%'.$search.'%')
                        ->orWhere('email', 'like', '%'.$search.'%');
                });
            })
            ->latest('id')
            ->paginate(12)
            ->withQueryString();

        return view('workspace.customers.index', compact('customers'));
    }

    public function create(): View
    {
        $this->authorize('create', Customer::class);

        return view('workspace.customers.create');
    }

    public function store(StoreCustomerRequest $request): RedirectResponse
    {
        $this->authorize('create', Customer::class);

        $payload = $request->validated();
        $payload['metadata'] = $this->parseJsonField($request, 'metadata_json');
        $payload['party_type'] = $payload['party_type'] ?? Customer::PARTY_TYPE_INDIVIDUAL;
        if (! empty($payload['country_code'])) {
            $payload['country_code'] = strtoupper((string) $payload['country_code']);
        }

        Customer::query()->create($payload);

        return redirect()->route('workspace.customers.index')->with('success', 'تم إنشاء العميل.');
    }

    public function edit(Customer $customer): View
    {
        $this->authorize('update', $customer);

        return view('workspace.customers.edit', [
            'customer' => $customer,
            'metadataJson' => json_encode($customer->metadata ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
        ]);
    }

    public function update(UpdateCustomerRequest $request, Customer $customer): RedirectResponse
    {
        $this->authorize('update', $customer);

        $payload = $request->validated();
        $payload['metadata'] = $this->parseJsonField($request, 'metadata_json', $customer->metadata ?? []);
        if (! empty($payload['country_code'])) {
            $payload['country_code'] = strtoupper((string) $payload['country_code']);
        }
        $customer->update($payload);

        return redirect()->route('workspace.customers.index')->with('success', 'تم تحديث العميل.');
    }

    public function destroy(Customer $customer): RedirectResponse
    {
        $this->authorize('delete', $customer);

        $customer->delete();

        return redirect()->route('workspace.customers.index')->with('success', 'تم حذف العميل.');
    }

    public function storeNote(Request $request, Customer $customer): RedirectResponse
    {
        $this->authorize('update', $customer);

        $validated = $request->validate([
            'body' => ['required', 'string', 'max:5000'],
        ]);

        $this->crm->addNote($customer, $validated['body'], $request->user());

        return back()->with('success', 'تمت إضافة الملاحظة.');
    }

    public function attachTag(Request $request, Customer $customer): RedirectResponse
    {
        $this->authorize('update', $customer);

        $validated = $request->validate([
            'tag_id' => ['required', 'integer'],
        ]);

        $tag = CustomerTag::query()
            ->where('workspace_id', $customer->workspace_id)
            ->whereKey($validated['tag_id'])
            ->firstOrFail();

        $this->crm->attachTag($customer, $tag);

        return back()->with('success', 'تم ربط الوسم بالعميل.');
    }

    public function detachTag(Customer $customer, CustomerTag $tag): RedirectResponse
    {
        $this->authorize('update', $customer);

        $this->crm->detachTag($customer, $tag);

        return back()->with('success', 'تم إزالة الوسم من العميل.');
    }

    public function attachGroup(Request $request, Customer $customer): RedirectResponse
    {
        $this->authorize('update', $customer);

        $validated = $request->validate([
            'group_id' => ['required', 'integer'],
        ]);

        $group = CustomerGroup::query()
            ->where('workspace_id', $customer->workspace_id)
            ->whereKey($validated['group_id'])
            ->firstOrFail();

        $this->crm->attachGroup($customer, $group);

        return back()->with('success', 'تم إضافة العميل إلى المجموعة.');
    }

    public function detachGroup(Customer $customer, CustomerGroup $group): RedirectResponse
    {
        $this->authorize('update', $customer);

        $this->crm->detachGroup($customer, $group);

        return back()->with('success', 'تم إزالة العميل من المجموعة.');
    }
}
