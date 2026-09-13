<?php

namespace App\Http\Controllers\Workspace\Finance;

use App\Models\Finance\FinanceAccount;
use App\Models\Finance\FinanceInvoice;
use App\Models\Finance\FinanceSetting;
use App\Models\Finance\FinanceTaxRate;
use App\Models\Finance\FinanceTreasuryAccount;
use App\Services\Finance\FinanceBootstrapService;
use App\Services\Finance\Tax\TaxCalculationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

class SettingsController extends FinanceBaseController
{
    public function __construct(
        private readonly FinanceBootstrapService $financeBootstrapService,
    ) {}

    public function index(Request $request): View
    {
        $this->authorizeFinance($request, 'finance.settings');
        $workspace = $this->currentWorkspace();
        $this->financeBootstrapService->ensureWorkspaceFinanceSetup($workspace);

        return view('workspace.finance.settings.index', [
            'setting' => FinanceSetting::forWorkspaceId((int) $workspace->id),
            'taxRates' => FinanceTaxRate::query()->orderByDesc('is_default')->orderBy('id')->get(),
            'treasuryAccounts' => FinanceTreasuryAccount::query()->with('linkedAccount')->orderBy('type')->orderBy('name')->get(),
            'financeAccounts' => FinanceAccount::query()->orderBy('code')->get(['id', 'code', 'name', 'type']),
        ]);
    }

    public function updateCompany(Request $request): RedirectResponse
    {
        $this->authorizeFinance($request, 'finance.settings');
        $workspace = $this->currentWorkspace();

        $validated = $request->validate([
            'company_name' => ['nullable', 'string', 'max:255'],
            'company_name_ar' => ['nullable', 'string', 'max:255'],
            'vat_number' => ['nullable', 'string', 'max:255'],
            'commercial_registration' => ['nullable', 'string', 'max:255'],
            'address_line' => ['nullable', 'string', 'max:255'],
            'building_number' => ['nullable', 'string', 'max:255'],
            'street' => ['nullable', 'string', 'max:255'],
            'district' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:255'],
            'postal_code' => ['nullable', 'string', 'max:50'],
            'country_code' => ['nullable', 'string', 'size:2'],
            'phone' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'website' => ['nullable', 'string', 'max:255'],
            'currency' => ['nullable', 'string', 'size:3'],
            'invoice_prefix' => ['nullable', 'string', 'max:20'],
            'invoice_primary_color' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'invoice_footer_text' => ['nullable', 'string', 'max:2000'],
            'default_payment_terms' => ['nullable', 'string', 'max:255'],
            'default_vat_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'allow_manual_invoice_numbers' => ['nullable', 'boolean'],
            'logo' => ['nullable', 'image', 'max:4096'],
            'remove_logo' => ['nullable', 'boolean'],
        ]);

        $setting = FinanceSetting::withoutGlobalScopes()->firstOrCreate(
            ['workspace_id' => $workspace->id],
            [
                'workspace_id' => $workspace->id,
                'currency' => 'SAR',
                'country_code' => 'SA',
                'invoice_prefix' => 'INV',
                'next_invoice_sequence' => 1,
                'allow_manual_invoice_numbers' => false,
                'default_vat_rate' => TaxCalculationService::FALLBACK_STANDARD_RATE,
            ]
        );

        $validated['allow_manual_invoice_numbers'] = $request->boolean('allow_manual_invoice_numbers');

        if ($request->boolean('remove_logo') && $setting->logo_path) {
            if ($this->shouldDeleteLogoFile($setting->logo_path)) {
                Storage::disk('public')->delete($setting->logo_path);
            }
            $validated['logo_path'] = null;
        }

        if ($request->hasFile('logo')) {
            $previousLogoPath = $setting->logo_path;
            $validated['logo_path'] = $request->file('logo')->store('workspaces/'.$workspace->id.'/finance/company', 'public');

            if ($previousLogoPath && $this->shouldDeleteLogoFile($previousLogoPath)) {
                Storage::disk('public')->delete($previousLogoPath);
            }
        }

        $this->stripUnknownSettingColumns($validated);
        unset($validated['logo'], $validated['remove_logo']);
        $setting->update($validated);

        return redirect()->route('workspace.finance.settings.index')->with('success', 'تم تحديث إعدادات المنشأة.');
    }

    private function shouldDeleteLogoFile(string $logoPath): bool
    {
        if (! Schema::hasColumn('finance_invoices', 'company_snapshot')) {
            return true;
        }

        $query = FinanceInvoice::withoutGlobalScopes()
            ->where('company_snapshot->logo_path', $logoPath);

        if (FinanceInvoice::hasSeparatedStatusColumns()) {
            $query->where(function ($builder): void {
                $builder->whereInvoiceStatus('issued')
                    ->orWhere(function ($cancelledQuery): void {
                        $cancelledQuery->whereInvoiceStatus('cancelled');
                    });
            });
        } else {
            $query->whereIn('status', ['sent', 'unpaid', 'partial', 'paid', 'overdue', 'cancelled']);
        }

        $isReferencedByHistoricalInvoice = $query->exists();

        return ! $isReferencedByHistoricalInvoice;
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function stripUnknownSettingColumns(array &$payload): void
    {
        $optionalColumns = [
            'website',
            'invoice_primary_color',
            'invoice_footer_text',
            'allow_manual_invoice_numbers',
        ];

        foreach ($optionalColumns as $column) {
            if (! Schema::hasColumn('finance_settings', $column)) {
                unset($payload[$column]);
            }
        }
    }

    public function storeTaxRate(Request $request): RedirectResponse
    {
        $this->authorizeFinance($request, 'finance.settings');

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:32'],
            'type' => ['required', 'in:standard,zero_rated,exempt,out_of_scope'],
            'rate' => ['required', 'numeric', 'min:0', 'max:100'],
            'is_default' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        if ($request->boolean('is_default')) {
            FinanceTaxRate::query()->update(['is_default' => false]);
        }

        FinanceTaxRate::query()->updateOrCreate(
            ['code' => $validated['code']],
            [
                ...$validated,
                'is_default' => $request->boolean('is_default'),
                'is_active' => $request->boolean('is_active', true),
            ]
        );

        return redirect()->route('workspace.finance.settings.index')->with('success', 'تم حفظ إعدادات الضريبة.');
    }

    public function storeTreasuryAccount(Request $request): RedirectResponse
    {
        $this->authorizeFinance($request, 'finance.settings');

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', 'in:cash,bank'],
            'currency' => ['required', 'string', 'size:3'],
            'opening_balance' => ['nullable', 'numeric'],
            'current_balance' => ['nullable', 'numeric'],
            'account_number' => ['nullable', 'string', 'max:255'],
            'iban' => ['nullable', 'string', 'max:255'],
            'bank_name' => ['nullable', 'string', 'max:255'],
            'linked_finance_account_id' => ['nullable', 'integer'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        FinanceTreasuryAccount::query()->updateOrCreate(
            ['name' => $validated['name']],
            [
                ...$validated,
                'opening_balance' => $validated['opening_balance'] ?? 0,
                'current_balance' => $validated['current_balance'] ?? ($validated['opening_balance'] ?? 0),
                'is_active' => $request->boolean('is_active', true),
            ]
        );

        return redirect()->route('workspace.finance.settings.index')->with('success', 'تم حفظ حساب النقد/البنك.');
    }
}
