<?php

namespace App\Http\Controllers\Workspace\Finance;

use App\Models\Finance\FinanceSupplier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SupplierController extends FinanceBaseController
{
    public function index(Request $request): View
    {
        $this->authorizeFinance($request, 'finance.view');

        $suppliers = FinanceSupplier::query()
            ->when($request->string('search')->toString(), function ($query, $search): void {
                $query->where(function ($inner) use ($search): void {
                    $inner->where('name', 'like', '%'.$search.'%')
                        ->orWhere('arabic_name', 'like', '%'.$search.'%')
                        ->orWhere('phone', 'like', '%'.$search.'%')
                        ->orWhere('email', 'like', '%'.$search.'%');
                });
            })
            ->latest('id')
            ->paginate(15)
            ->withQueryString();

        return view('workspace.finance.suppliers.index', [
            'suppliers' => $suppliers,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorizeFinance($request, 'finance.manage');

        $payload = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'arabic_name' => ['nullable', 'string', 'max:255'],
            'vat_number' => ['nullable', 'string', 'max:255'],
            'commercial_registration' => ['nullable', 'string', 'max:255'],
            'address' => ['nullable', 'string'],
            'phone' => ['nullable', 'string', 'max:64'],
            'email' => ['nullable', 'email', 'max:255'],
            'opening_balance' => ['nullable', 'numeric', 'min:0'],
            'payment_terms' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'in:active,inactive'],
        ]);

        FinanceSupplier::query()->create([
            ...$payload,
            'opening_balance' => $payload['opening_balance'] ?? 0,
            'status' => $payload['status'] ?? 'active',
        ]);

        return redirect()->route('workspace.finance.suppliers.index')->with('success', 'تم إنشاء المورد.');
    }

    public function update(Request $request, FinanceSupplier $supplier): RedirectResponse
    {
        $this->authorizeFinance($request, 'finance.manage');

        $payload = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'arabic_name' => ['nullable', 'string', 'max:255'],
            'vat_number' => ['nullable', 'string', 'max:255'],
            'commercial_registration' => ['nullable', 'string', 'max:255'],
            'address' => ['nullable', 'string'],
            'phone' => ['nullable', 'string', 'max:64'],
            'email' => ['nullable', 'email', 'max:255'],
            'opening_balance' => ['nullable', 'numeric', 'min:0'],
            'payment_terms' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'in:active,inactive'],
        ]);

        $supplier->update([
            ...$payload,
            'opening_balance' => $payload['opening_balance'] ?? 0,
            'status' => $payload['status'] ?? 'active',
        ]);

        return redirect()->route('workspace.finance.suppliers.index')->with('success', 'تم تحديث بيانات المورد.');
    }
}
