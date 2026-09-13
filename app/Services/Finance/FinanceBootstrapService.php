<?php

namespace App\Services\Finance;

use App\Models\Finance\FinanceFiscalYear;
use App\Models\Finance\FinanceSetting;
use App\Models\Finance\FinanceTaxRate;
use App\Models\Finance\FinanceTreasuryAccount;
use App\Models\Workspace;
use App\Services\Finance\Tax\TaxCalculationService;
use App\Support\Compliance\ComplianceManager;
use Illuminate\Support\Facades\DB;

class FinanceBootstrapService
{
    public function __construct(
        private readonly ChartOfAccountsService $chartOfAccountsService,
        private readonly ComplianceManager $complianceManager,
    ) {}

    public function ensureWorkspaceFinanceSetup(Workspace $workspace): void
    {
        DB::transaction(function () use ($workspace): void {
            $setting = FinanceSetting::withoutGlobalScopes()->firstOrCreate(
                ['workspace_id' => $workspace->id],
                [
                    'workspace_id' => $workspace->id,
                    'country_code' => 'SA',
                    'currency' => 'SAR',
                    'invoice_prefix' => 'INV',
                    'next_invoice_sequence' => 1,
                    'quote_prefix' => 'Q',
                    'next_quote_sequence' => 1,
                    'receipt_prefix' => 'RCT',
                    'next_receipt_sequence' => 1,
                    'allow_manual_invoice_numbers' => false,
                    'default_vat_rate' => TaxCalculationService::FALLBACK_STANDARD_RATE,
                ]
            );

            $this->chartOfAccountsService->ensureDefaultAccounts($workspace);

            FinanceTaxRate::withoutGlobalScopes()->firstOrCreate(
                ['workspace_id' => $workspace->id, 'code' => 'VAT_STD_15'],
                [
                    'workspace_id' => $workspace->id,
                    'name' => 'Saudi VAT Standard',
                    'code' => 'VAT_STD_15',
                    'type' => 'standard',
                    'rate' => $setting->default_vat_rate,
                    'is_default' => true,
                    'is_active' => true,
                ]
            );

            FinanceTaxRate::withoutGlobalScopes()->firstOrCreate(
                ['workspace_id' => $workspace->id, 'code' => 'VAT_ZERO'],
                [
                    'workspace_id' => $workspace->id,
                    'name' => 'Zero Rated',
                    'code' => 'VAT_ZERO',
                    'type' => 'zero_rated',
                    'rate' => 0,
                    'is_default' => false,
                    'is_active' => true,
                ]
            );

            FinanceTaxRate::withoutGlobalScopes()->firstOrCreate(
                ['workspace_id' => $workspace->id, 'code' => 'VAT_EXEMPT'],
                [
                    'workspace_id' => $workspace->id,
                    'name' => 'Exempt',
                    'code' => 'VAT_EXEMPT',
                    'type' => 'exempt',
                    'rate' => 0,
                    'is_default' => false,
                    'is_active' => true,
                ]
            );

            FinanceTaxRate::withoutGlobalScopes()->firstOrCreate(
                ['workspace_id' => $workspace->id, 'code' => 'VAT_OUT_SCOPE'],
                [
                    'workspace_id' => $workspace->id,
                    'name' => 'Out of Scope',
                    'code' => 'VAT_OUT_SCOPE',
                    'type' => 'out_of_scope',
                    'rate' => 0,
                    'is_default' => false,
                    'is_active' => true,
                ]
            );

            $this->ensureCountryTaxProfiles($workspace->id, (string) $setting->country_code);

            $cash = $this->chartOfAccountsService->byCode('1000', $workspace->id);
            $bank = $this->chartOfAccountsService->byCode('1100', $workspace->id);

            FinanceTreasuryAccount::withoutGlobalScopes()->firstOrCreate(
                ['workspace_id' => $workspace->id, 'name' => 'Main Cash'],
                [
                    'workspace_id' => $workspace->id,
                    'name' => 'Main Cash',
                    'type' => 'cash',
                    'currency' => $setting->currency,
                    'opening_balance' => 0,
                    'current_balance' => 0,
                    'linked_finance_account_id' => $cash?->id,
                ]
            );

            FinanceTreasuryAccount::withoutGlobalScopes()->firstOrCreate(
                ['workspace_id' => $workspace->id, 'name' => 'Main Bank'],
                [
                    'workspace_id' => $workspace->id,
                    'name' => 'Main Bank',
                    'type' => 'bank',
                    'currency' => $setting->currency,
                    'opening_balance' => 0,
                    'current_balance' => 0,
                    'linked_finance_account_id' => $bank?->id,
                ]
            );

            FinanceFiscalYear::withoutGlobalScopes()->firstOrCreate(
                ['workspace_id' => $workspace->id, 'name' => now()->format('Y')],
                [
                    'workspace_id' => $workspace->id,
                    'name' => now()->format('Y'),
                    'start_date' => now()->startOfYear()->toDateString(),
                    'end_date' => now()->endOfYear()->toDateString(),
                    'status' => 'open',
                ]
            );
        });
    }

    private function ensureCountryTaxProfiles(int $workspaceId, string $countryCode): void
    {
        $workspaceCountry = strtoupper($countryCode);

        foreach ($this->complianceManager->all() as $profile) {
            $isDefault = $profile->countryCode() === $workspaceCountry
                && $profile->countryCode() !== 'SA';

            FinanceTaxRate::withoutGlobalScopes()->firstOrCreate(
                ['workspace_id' => $workspaceId, 'code' => $profile->standardTaxCode()],
                [
                    'workspace_id' => $workspaceId,
                    'name' => $profile->standardTaxName(),
                    'code' => $profile->standardTaxCode(),
                    'type' => 'standard',
                    'rate' => $profile->standardTaxRate(),
                    'is_default' => $isDefault,
                    'is_active' => true,
                ]
            );
        }
    }
}
