<?php

namespace App\Services\Contracts;

use App\Models\Contract\Contract;
use App\Models\Contract\ContractAttachment;
use App\Models\Customer;
use App\Models\Finance\FinanceBillingSchedule;
use App\Models\Finance\FinanceSetting;
use App\Models\Projects\FinanceProject;
use App\Models\Workspace;
use App\Services\Finance\Tax\TaxCalculationService;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

class ContractService
{
    public function create(Workspace $workspace, array $payload, int $actorUserId, array $uploadedFiles = []): Contract
    {
        return DB::transaction(function () use ($workspace, $payload, $actorUserId, $uploadedFiles): Contract {
            $customer = $this->resolveCustomer($workspace->id, Arr::get($payload, 'customer_id'));
            [$items, $value] = $this->normalizeItemsAndValue(
                Arr::get($payload, 'items', []),
                Arr::get($payload, 'value')
            );
            [$companySnapshot, $customerSnapshot, $pdfSnapshot] = $this->buildSnapshots($customer);

            $attributes = [
                'workspace_id' => $workspace->id,
                'customer_id' => $customer?->id,
                'contract_number' => (string) (Arr::get($payload, 'contract_number') ?: $this->nextContractNumber($workspace->id)),
                'title' => (string) Arr::get($payload, 'title', ''),
                'status' => (string) Arr::get($payload, 'status', 'draft'),
                'value' => $value,
                'currency' => (string) Arr::get($payload, 'currency', 'SAR'),
                'start_date' => Arr::get($payload, 'start_date'),
                'end_date' => Arr::get($payload, 'end_date'),
                'terms' => Arr::get($payload, 'terms'),
                'notes' => Arr::get($payload, 'notes'),
                'company_snapshot' => $companySnapshot,
                'customer_snapshot' => $customerSnapshot,
                'pdf_snapshot' => $pdfSnapshot,
                'created_by' => $actorUserId,
            ];
            if (Schema::hasColumn('contracts', 'project_id')) {
                $attributes['project_id'] = $this->resolveProjectId($workspace->id, Arr::get($payload, 'project_id'));
            }

            $contract = Contract::query()->create($attributes);

            $this->syncItems($contract, $items);
            $this->storeUploadedAttachments($contract, $uploadedFiles);

            return $contract->load(['customer', 'items', 'attachments']);
        });
    }

    public function update(Contract $contract, array $payload, array $uploadedFiles = []): Contract
    {
        return DB::transaction(function () use ($contract, $payload, $uploadedFiles): Contract {
            $customerId = Arr::get($payload, 'customer_id');
            $customer = $this->resolveCustomer((int) $contract->workspace_id, $customerId);
            [$items, $value] = $this->normalizeItemsAndValue(
                Arr::get($payload, 'items', []),
                Arr::get($payload, 'value')
            );

            $updates = [
                'customer_id' => $customer?->id,
                'contract_number' => (string) (Arr::get($payload, 'contract_number') ?: $contract->contract_number),
                'title' => (string) Arr::get($payload, 'title', $contract->title),
                'value' => $value,
                'currency' => (string) Arr::get($payload, 'currency', $contract->currency),
                'start_date' => Arr::get($payload, 'start_date'),
                'end_date' => Arr::get($payload, 'end_date'),
                'terms' => Arr::get($payload, 'terms'),
                'notes' => Arr::get($payload, 'notes'),
            ];

            if (Schema::hasColumn('contracts', 'project_id')) {
                $updates['project_id'] = $this->resolveProjectId((int) $contract->workspace_id, Arr::get($payload, 'project_id'));
            }

            if ($contract->status === 'draft') {
                [$companySnapshot, $customerSnapshot, $pdfSnapshot] = $this->buildSnapshots($customer);
                $updates['company_snapshot'] = $companySnapshot;
                $updates['customer_snapshot'] = $customerSnapshot;
                $updates['pdf_snapshot'] = $pdfSnapshot;
            }

            $contract->update($updates);
            $this->syncItems($contract, $items);
            $this->storeUploadedAttachments($contract, $uploadedFiles);

            return $contract->load(['customer', 'items', 'attachments']);
        });
    }

    public function activate(Contract $contract, int $actorUserId): Contract
    {
        if (in_array($contract->status, ['closed', 'cancelled'], true)) {
            throw new \RuntimeException('لا يمكن تفعيل عقد مغلق أو ملغي.');
        }

        if ($contract->status === 'open') {
            return $contract;
        }

        return DB::transaction(function () use ($contract, $actorUserId): Contract {
            $contract->loadMissing('customer');

            $companySnapshot = is_array($contract->company_snapshot) ? $contract->company_snapshot : [];
            $customerSnapshot = is_array($contract->customer_snapshot) ? $contract->customer_snapshot : [];
            $pdfSnapshot = is_array($contract->pdf_snapshot) ? $contract->pdf_snapshot : [];

            if ($companySnapshot === [] || $customerSnapshot === [] || $pdfSnapshot === []) {
                [$companySnapshot, $customerSnapshot, $pdfSnapshot] = $this->buildSnapshots($contract->customer);
            }

            $contract->update([
                'status' => 'open',
                'activated_at' => now(),
                'company_snapshot' => $companySnapshot,
                'customer_snapshot' => $customerSnapshot,
                'pdf_snapshot' => $pdfSnapshot,
                'metadata' => array_merge(is_array($contract->metadata) ? $contract->metadata : [], [
                    'activated_by' => $actorUserId,
                ]),
            ]);

            return $contract->fresh(['customer', 'items', 'attachments']);
        });
    }

    public function close(Contract $contract): Contract
    {
        if ($contract->status === 'cancelled') {
            throw new \RuntimeException('لا يمكن إغلاق عقد ملغي.');
        }

        return DB::transaction(function () use ($contract): Contract {
            $contract->update([
                'status' => 'closed',
                'closed_at' => now(),
            ]);
            $this->stopLinkedBillingSchedules($contract);

            return $contract->fresh(['customer', 'items', 'attachments']);
        });
    }

    public function cancel(Contract $contract): Contract
    {
        if ($contract->status === 'closed') {
            throw new \RuntimeException('لا يمكن إلغاء عقد مغلق.');
        }

        return DB::transaction(function () use ($contract): Contract {
            $contract->update([
                'status' => 'cancelled',
                'cancelled_at' => now(),
            ]);
            $this->stopLinkedBillingSchedules($contract);

            return $contract->fresh(['customer', 'items', 'attachments']);
        });
    }

    /**
     * Closed = completed successfully. Cancelled = terminated.
     * Both must stop future invoice generation from linked schedules.
     */
    private function stopLinkedBillingSchedules(Contract $contract): void
    {
        if (! Schema::hasTable('finance_billing_schedules')) {
            return;
        }

        FinanceBillingSchedule::withoutGlobalScopes()
            ->where('workspace_id', $contract->workspace_id)
            ->where('contract_id', $contract->id)
            ->whereIn('status', [
                FinanceBillingSchedule::STATUS_DRAFT,
                FinanceBillingSchedule::STATUS_ACTIVE,
                FinanceBillingSchedule::STATUS_PAUSED,
            ])
            ->update([
                'status' => FinanceBillingSchedule::STATUS_CANCELLED,
                'next_run_on' => null,
            ]);
    }

    /**
     * @param  array<int, mixed>  $uploadedFiles
     */
    public function storeAttachments(Contract $contract, array $uploadedFiles): Contract
    {
        $this->storeUploadedAttachments($contract, $uploadedFiles);

        return $contract->fresh(['customer', 'items', 'attachments', 'billingSchedules']) ?? $contract;
    }

    public function deleteAttachment(ContractAttachment $attachment): void
    {
        $path = $attachment->file_path;
        $attachment->delete();

        if (is_string($path) && $path !== '') {
            Storage::disk('public')->delete($path);
        }
    }

    /**
     * @return array{0:array<int,array<string,mixed>>,1:float}
     */
    private function normalizeItemsAndValue(array $rawItems, mixed $rawValue): array
    {
        $items = [];
        $computed = 0.0;

        foreach ($rawItems as $index => $rawItem) {
            $title = trim((string) Arr::get($rawItem, 'title'));
            if ($title === '') {
                continue;
            }

            $quantity = max(0.0, (float) Arr::get($rawItem, 'quantity', 1));
            $unitPrice = max(0.0, (float) Arr::get($rawItem, 'unit_price', 0));
            $lineTotal = $this->money($quantity * $unitPrice);
            $computed += $lineTotal;

            $items[] = [
                'sort_order' => (int) $index,
                'title' => $title,
                'description' => trim((string) Arr::get($rawItem, 'description')) ?: null,
                'quantity' => $this->quantity($quantity),
                'unit_price' => $this->money($unitPrice),
                'total' => $lineTotal,
            ];
        }

        $value = $computed > 0
            ? $this->money($computed)
            : $this->money(max(0.0, (float) $rawValue));

        return [$items, $value];
    }

    private function syncItems(Contract $contract, array $items): void
    {
        $contract->items()->delete();

        foreach ($items as $item) {
            $contract->items()->create([
                'workspace_id' => $contract->workspace_id,
                'sort_order' => (int) $item['sort_order'],
                'title' => (string) $item['title'],
                'description' => $item['description'],
                'quantity' => (float) $item['quantity'],
                'unit_price' => (float) $item['unit_price'],
                'total' => (float) $item['total'],
            ]);
        }
    }

    private function storeUploadedAttachments(Contract $contract, array $uploadedFiles): void
    {
        foreach ($uploadedFiles as $file) {
            if (! method_exists($file, 'store')) {
                continue;
            }

            $storedPath = $file->store('workspaces/'.$contract->workspace_id.'/contracts/attachments', 'public');
            $contract->attachments()->create([
                'workspace_id' => $contract->workspace_id,
                'file_path' => $storedPath,
                'file_name' => $file->getClientOriginalName(),
                'file_type' => $file->getClientMimeType(),
                'file_size' => $file->getSize(),
            ]);
        }
    }

    private function resolveProjectId(int $workspaceId, mixed $projectId): ?int
    {
        if (! $projectId) {
            return null;
        }

        $exists = FinanceProject::withoutGlobalScopes()
            ->where('workspace_id', $workspaceId)
            ->whereKey((int) $projectId)
            ->exists();

        return $exists ? (int) $projectId : null;
    }

    private function resolveCustomer(int $workspaceId, mixed $customerId): ?Customer
    {
        if (! $customerId) {
            return null;
        }

        return Customer::withoutGlobalScopes()
            ->where('workspace_id', $workspaceId)
            ->where('id', (int) $customerId)
            ->first();
    }

    /**
     * @return array{0:array<string,mixed>,1:array<string,mixed>,2:array<string,mixed>}
     */
    private function buildSnapshots(?Customer $customer): array
    {
        $setting = FinanceSetting::query()->first();

        $company = [
            'company_name' => $setting?->company_name ?: config('app.name', 'HASem'),
            'company_name_ar' => $setting?->company_name_ar ?: null,
            'vat_number' => $setting?->vat_number ?: null,
            'address_line' => $setting?->address_line ?: null,
            'city' => $setting?->city ?: null,
            'country_code' => $setting?->country_code ?: null,
            'phone' => $setting?->phone ?: null,
            'email' => $setting?->email ?: null,
            'website' => $setting?->website ?: null,
            'logo_path' => $setting?->logo_path ?: null,
        ];

        $customerSnapshot = [
            'name' => $customer?->name ?: null,
            'email' => $customer?->email ?: null,
            'phone' => $customer?->phone ?: null,
            'vat_number' => $customer?->vat_number ?: null,
            'address' => $customer?->address ?: null,
        ];

        $pdf = [
            'primary_color' => $setting?->invoice_primary_color ?: '#06C2A4',
            'footer_text' => $setting?->invoice_footer_text ?: null,
            'currency' => $setting?->currency ?: 'SAR',
        ];

        return [$company, $customerSnapshot, $pdf];
    }

    private function nextContractNumber(int $workspaceId): string
    {
        $settings = FinanceSetting::withoutGlobalScopes()
            ->where('workspace_id', $workspaceId)
            ->lockForUpdate()
            ->first();

        if (! $settings) {
            $settings = FinanceSetting::withoutGlobalScopes()->create([
                'workspace_id' => $workspaceId,
                'currency' => 'SAR',
                'country_code' => 'SA',
                'invoice_prefix' => 'INV',
                'next_invoice_sequence' => 1,
                'next_contract_sequence' => 1,
                'default_vat_rate' => TaxCalculationService::FALLBACK_STANDARD_RATE,
            ]);
        }

        $sequence = (int) ($settings->next_contract_sequence ?? 0);
        if ($sequence < 1) {
            $sequence = (int) Contract::withoutGlobalScopes()
                ->where('workspace_id', $workspaceId)
                ->lockForUpdate()
                ->count() + 1;
        }

        $number = 'CTR-'.str_pad((string) $sequence, 6, '0', STR_PAD_LEFT);
        $settings->update(['next_contract_sequence' => $sequence + 1]);

        return $number;
    }

    private function money(float $value): float
    {
        return round($value, 2);
    }

    private function quantity(float $value): float
    {
        return round($value, 3);
    }
}
