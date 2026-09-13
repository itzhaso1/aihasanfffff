<?php

namespace App\Http\Controllers\Api\Finance\V1;

use App\Http\Controllers\Api\Finance\Concerns\HandlesFinanceClient;
use App\Http\Controllers\Api\Finance\FinanceApiController;
use App\Models\Finance\FinanceInvoice;
use App\Models\Finance\FinanceSetting;
use App\Models\Finance\FinanceTaxRate;
use App\Models\Finance\FinanceTreasuryAccount;
use App\Services\Finance\Api\FinanceClientPresenter;
use App\Services\Finance\FinanceBootstrapService;
use App\Services\Finance\Tax\TaxCalculationService;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

class SettingsController extends FinanceApiController
{
    use HandlesFinanceClient;

    public function __construct(
        private readonly WorkspaceContext $workspaceContext,
        private readonly FinanceBootstrapService $financeBootstrapService,
        private readonly FinanceClientPresenter $presenter,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $workspace = $this->clientWorkspace($this->workspaceContext);
        $this->clientActor($request, $workspace, 'finance.settings');
        $this->financeBootstrapService->ensureWorkspaceFinanceSetup($workspace);
        $setting = FinanceSetting::forWorkspaceId((int) $workspace->id);

        return $this->ok($setting ? $this->presenter->settings($setting) : null);
    }

    public function update(Request $request): JsonResponse
    {
        $workspace = $this->clientWorkspace($this->workspaceContext);
        $this->clientActor($request, $workspace, 'finance.settings');

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
            'default_payment_terms' => ['nullable', 'string', 'max:255'],
            'default_vat_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'invoice_prefix' => ['nullable', 'string', 'max:20'],
            'invoice_primary_color' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'invoice_footer_text' => ['nullable', 'string', 'max:2000'],
            'allow_manual_invoice_numbers' => ['nullable', 'boolean'],
        ]);
        if ($request->exists('allow_manual_invoice_numbers')) {
            $validated['allow_manual_invoice_numbers'] = $request->boolean('allow_manual_invoice_numbers');
        }

        unset(
            $validated['zatca_integration_mode'],
            $validated['zatca_certificate_serial'],
            $validated['metadata'],
        );

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

        $setting->fill($validated);
        $setting->save();

        return $this->ok($this->presenter->settings($setting->fresh()), message: 'تم حفظ إعدادات الشركة.');
    }

    public function logo(Request $request): mixed
    {
        $workspace = $this->clientWorkspace($this->workspaceContext);
        $this->clientActor($request, $workspace, 'finance.settings');
        $setting = FinanceSetting::forWorkspaceId((int) $workspace->id);
        abort_unless($setting?->logo_path && Storage::disk('public')->exists($setting->logo_path), 404);

        return Storage::disk('public')->download($setting->logo_path, basename((string) $setting->logo_path));
    }

    public function uploadLogo(Request $request): JsonResponse
    {
        $workspace = $this->clientWorkspace($this->workspaceContext);
        $this->clientActor($request, $workspace, 'finance.settings');
        $request->validate([
            'logo' => ['required', 'image', 'max:4096'],
        ]);

        $setting = $this->settingFor($workspace->id);
        $previousLogoPath = $setting->logo_path;
        $setting->logo_path = $request->file('logo')->store('workspaces/'.$workspace->id.'/finance/company', 'public');
        $setting->save();

        if ($previousLogoPath && $this->shouldDeleteLogoFile($previousLogoPath)) {
            Storage::disk('public')->delete($previousLogoPath);
        }

        return $this->ok($this->presenter->settings($setting->fresh()), message: 'تم تحديث شعار المنشأة.');
    }

    public function removeLogo(Request $request): JsonResponse
    {
        $workspace = $this->clientWorkspace($this->workspaceContext);
        $this->clientActor($request, $workspace, 'finance.settings');
        $setting = $this->settingFor($workspace->id);

        if ($setting->logo_path) {
            if ($this->shouldDeleteLogoFile($setting->logo_path)) {
                Storage::disk('public')->delete($setting->logo_path);
            }
            $setting->logo_path = null;
            $setting->save();
        }

        return $this->ok($this->presenter->settings($setting->fresh()), message: 'تم حذف شعار المنشأة.');
    }

    public function storeTaxRate(Request $request): JsonResponse
    {
        $workspace = $this->clientWorkspace($this->workspaceContext);
        $this->clientActor($request, $workspace, 'finance.settings');

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

        $rate = FinanceTaxRate::query()->updateOrCreate(
            ['code' => $validated['code']],
            [
                ...$validated,
                'is_default' => $request->boolean('is_default'),
                'is_active' => $request->boolean('is_active', true),
            ]
        );

        return $this->ok($this->presenter->taxRate($rate), message: 'تم حفظ إعدادات الضريبة.');
    }

    public function storeTreasuryAccount(Request $request): JsonResponse
    {
        $workspace = $this->clientWorkspace($this->workspaceContext);
        $this->clientActor($request, $workspace, 'finance.settings');

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

        $account = FinanceTreasuryAccount::query()->updateOrCreate(
            ['name' => $validated['name']],
            [
                ...$validated,
                'opening_balance' => $validated['opening_balance'] ?? 0,
                'current_balance' => $validated['current_balance'] ?? ($validated['opening_balance'] ?? 0),
                'is_active' => $request->boolean('is_active', true),
            ]
        );

        return $this->ok($this->presenter->treasuryAccount($account), message: 'تم حفظ حساب النقد/البنك.');
    }

    private function settingFor(int $workspaceId): FinanceSetting
    {
        return FinanceSetting::withoutGlobalScopes()->firstOrCreate(
            ['workspace_id' => $workspaceId],
            [
                'workspace_id' => $workspaceId,
                'currency' => 'SAR',
                'country_code' => 'SA',
                'invoice_prefix' => 'INV',
                'next_invoice_sequence' => 1,
                'allow_manual_invoice_numbers' => false,
                'default_vat_rate' => TaxCalculationService::FALLBACK_STANDARD_RATE,
            ]
        );
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

        return ! $query->exists();
    }
}
