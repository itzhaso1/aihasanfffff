<?php

namespace App\Services\Finance;

use App\Models\Contract\Contract;
use App\Models\Finance\FinanceBillingSchedule;
use App\Models\Finance\FinanceInvoice;
use App\Models\Workspace;
use App\Services\Finance\Tax\TaxCalculationService;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class BillingScheduleService
{
    public function __construct(
        private readonly InvoiceService $invoiceService,
        private readonly TaxCalculationService $taxCalculator,
    ) {}

    /**
     * Finance-only recurring/installment schedule. Not SaaS subscription charging.
     *
     * @param  array<string,mixed>  $payload
     */
    public function create(Workspace $workspace, array $payload, int $actorUserId): FinanceBillingSchedule
    {
        $frequency = (string) ($payload['frequency'] ?? 'monthly');
        if (! in_array($frequency, ['weekly', 'monthly', 'quarterly', 'yearly', 'installment'], true)) {
            throw new RuntimeException('دورية الفوترة غير صالحة.');
        }

        $startDate = Carbon::parse((string) $payload['start_date'])->startOfDay();
        $amount = round(max(0, (float) ($payload['amount'] ?? 0)), 2);
        $taxProfile = $this->taxCalculator->defaultProfileForWorkspace($workspace);
        $items = $this->normalizeItemSnapshot(
            $payload['items'] ?? [],
            $amount,
            $taxProfile['rate'],
            $taxProfile['type']
        );
        if ($amount <= 0 && $items !== []) {
            $amount = round(array_sum(array_map(fn (array $item): float => (float) $item['total'], $items)), 2);
        }
        if ($amount <= 0) {
            throw new RuntimeException('قيمة جدول الفوترة يجب أن تكون أكبر من صفر.');
        }

        $occurrences = isset($payload['total_occurrences']) ? max(1, (int) $payload['total_occurrences']) : null;
        if ($frequency === 'installment' && $occurrences === null) {
            $occurrences = 2;
        }

        $status = (string) ($payload['status'] ?? FinanceBillingSchedule::STATUS_DRAFT);
        if (! in_array($status, [FinanceBillingSchedule::STATUS_DRAFT, FinanceBillingSchedule::STATUS_ACTIVE], true)) {
            $status = FinanceBillingSchedule::STATUS_DRAFT;
        }

        return FinanceBillingSchedule::query()->create([
            'workspace_id' => $workspace->id,
            'customer_id' => $payload['customer_id'] ?? null,
            'contract_id' => $payload['contract_id'] ?? null,
            'title' => (string) $payload['title'],
            'status' => $status,
            'frequency' => $frequency,
            'interval_count' => max(1, (int) ($payload['interval_count'] ?? 1)),
            'total_occurrences' => $occurrences,
            'generated_count' => 0,
            'amount' => $amount,
            'currency' => (string) ($payload['currency'] ?? 'SAR'),
            'start_date' => $startDate->toDateString(),
            'end_date' => ($payload['end_date'] ?? null) ?: null,
            'next_run_on' => $startDate->toDateString(),
            'auto_issue' => (bool) ($payload['auto_issue'] ?? false),
            'invoice_type' => (string) ($payload['invoice_type'] ?? 'sales'),
            'notes' => ($payload['notes'] ?? null) ?: null,
            'item_snapshot' => $items,
            'created_by' => $actorUserId,
        ]);
    }

    public function activate(FinanceBillingSchedule $schedule): FinanceBillingSchedule
    {
        if (in_array($schedule->status, [FinanceBillingSchedule::STATUS_COMPLETED, FinanceBillingSchedule::STATUS_CANCELLED], true)) {
            throw new RuntimeException('لا يمكن تفعيل جدول مكتمل أو ملغى.');
        }

        $schedule->update([
            'status' => FinanceBillingSchedule::STATUS_ACTIVE,
            'next_run_on' => $schedule->next_run_on ?: $schedule->start_date,
        ]);

        return $schedule->fresh();
    }

    public function pause(FinanceBillingSchedule $schedule): FinanceBillingSchedule
    {
        if ($schedule->status !== FinanceBillingSchedule::STATUS_ACTIVE) {
            throw new RuntimeException('يمكن إيقاف الجداول النشطة فقط.');
        }

        $schedule->update(['status' => FinanceBillingSchedule::STATUS_PAUSED]);

        return $schedule->fresh();
    }

    public function cancel(FinanceBillingSchedule $schedule): FinanceBillingSchedule
    {
        $schedule->update(['status' => FinanceBillingSchedule::STATUS_CANCELLED, 'next_run_on' => null]);

        return $schedule->fresh();
    }

    public function generateDueInvoices(?int $workspaceId = null, ?Carbon $onDate = null): int
    {
        $onDate = ($onDate ?? now())->startOfDay();
        $generated = 0;

        $query = FinanceBillingSchedule::withoutGlobalScopes()
            ->where('status', FinanceBillingSchedule::STATUS_ACTIVE)
            ->whereDate('next_run_on', '<=', $onDate->toDateString());

        if ($workspaceId) {
            $query->where('workspace_id', $workspaceId);
        }

        $query->orderBy('id')->chunkById(50, function ($schedules) use (&$generated, $onDate): void {
            foreach ($schedules as $schedule) {
                $generated += $this->generateOne($schedule, $onDate) ? 1 : 0;
            }
        });

        return $generated;
    }

    public function generateOne(FinanceBillingSchedule $schedule, ?Carbon $onDate = null): ?FinanceInvoice
    {
        return DB::transaction(function () use ($schedule, $onDate): ?FinanceInvoice {
            $locked = FinanceBillingSchedule::withoutGlobalScopes()
                ->whereKey($schedule->id)
                ->lockForUpdate()
                ->first();
            if (! $locked || $locked->status !== FinanceBillingSchedule::STATUS_ACTIVE) {
                return null;
            }

            if ($this->contractBlocksBilling($locked)) {
                return null;
            }

            $workspace = Workspace::query()->find($locked->workspace_id);
            if (! $workspace) {
                return null;
            }

            return app(WorkspaceContext::class)->runFor($workspace, function () use ($locked, $onDate, $workspace): ?FinanceInvoice {
            $onDate = ($onDate ?? now())->startOfDay();
            if ($locked->next_run_on && $locked->next_run_on->startOfDay()->gt($onDate)) {
                return null;
            }

            if ($locked->end_date && $locked->end_date->startOfDay()->lt($onDate)) {
                $locked->update(['status' => FinanceBillingSchedule::STATUS_COMPLETED, 'next_run_on' => null]);

                return null;
            }

            if ($locked->total_occurrences !== null && (int) $locked->generated_count >= (int) $locked->total_occurrences) {
                $locked->update(['status' => FinanceBillingSchedule::STATUS_COMPLETED, 'next_run_on' => null]);

                return null;
            }

            $occurrenceDate = $locked->next_run_on?->toDateString() ?? $onDate->toDateString();
            $occurrenceKey = $this->occurrenceKey($locked, $occurrenceDate);

            if (Schema::hasColumn('finance_invoices', 'billing_occurrence_key')) {
                $existing = FinanceInvoice::withoutGlobalScopes()
                    ->where('workspace_id', $locked->workspace_id)
                    ->where('billing_schedule_id', $locked->id)
                    ->where('billing_occurrence_key', $occurrenceKey)
                    ->first();

                if ($existing) {
                    $this->advanceScheduleIfStillDue($locked, $onDate, $occurrenceDate);

                    return $existing;
                }
            }

            $taxProfile = $this->taxCalculator->defaultProfileForWorkspace($workspace);

                $items = is_array($locked->item_snapshot) && $locked->item_snapshot !== []
                    ? $locked->item_snapshot
                    : [[
                        'product_name' => $locked->title,
                        'description' => $locked->notes,
                        'quantity' => 1,
                        'unit_price' => (float) $locked->amount,
                        'discount' => 0,
                        'tax_rate' => $taxProfile['rate'],
                        'tax_type' => $taxProfile['type'],
                    ]];

                try {
                    $invoice = $this->invoiceService->create($workspace, [
                        'type' => $locked->invoice_type ?: 'sales',
                        'customer_id' => $locked->customer_id,
                        'contract_id' => $locked->contract_id,
                        'billing_schedule_id' => $locked->id,
                        'billing_occurrence_key' => $occurrenceKey,
                        'issue_date' => $onDate->toDateString(),
                        'due_date' => $onDate->copy()->addDays(14)->toDateString(),
                        'currency' => $locked->currency,
                        'invoice_status' => $locked->auto_issue ? 'issued' : 'draft',
                        'notes' => 'فاتورة مجدولة: '.$locked->title,
                        'items' => $items,
                    ], (int) ($locked->created_by ?: $workspace->owner_user_id));
                } catch (UniqueConstraintViolationException) {
                    $existing = FinanceInvoice::withoutGlobalScopes()
                        ->where('workspace_id', $locked->workspace_id)
                        ->where('billing_schedule_id', $locked->id)
                        ->where('billing_occurrence_key', $occurrenceKey)
                        ->first();

                    if ($existing) {
                        $this->advanceScheduleIfStillDue($locked, $onDate, $occurrenceDate);

                        return $existing;
                    }

                    throw new RuntimeException('تعذر توليد فاتورة الجدول بسبب تكرار عملية متزامنة.');
                }

                $this->advanceScheduleIfStillDue($locked, $onDate, $occurrenceDate);

                return $invoice;
            });
        });
    }

    public function createFromContract(Contract $contract, array $payload, int $actorUserId): FinanceBillingSchedule
    {
        $workspace = Workspace::query()->findOrFail($contract->workspace_id);
        $occurrences = max(1, (int) ($payload['total_occurrences'] ?? 1));
        $amount = round(((float) $contract->value) / $occurrences, 2);

        $taxProfile = $this->taxCalculator->defaultProfileForWorkspace($workspace);

        return $this->create($workspace, [
            'customer_id' => $contract->customer_id,
            'contract_id' => $contract->id,
            'title' => (string) ($payload['title'] ?? ('فوترة العقد '.$contract->contract_number)),
            'frequency' => (string) ($payload['frequency'] ?? 'installment'),
            'interval_count' => (int) ($payload['interval_count'] ?? 1),
            'total_occurrences' => $occurrences,
            'amount' => $amount,
            'currency' => $contract->currency,
            'start_date' => $payload['start_date'] ?? ($contract->start_date?->toDateString() ?? now()->toDateString()),
            'end_date' => $payload['end_date'] ?? $contract->end_date?->toDateString(),
            'auto_issue' => (bool) ($payload['auto_issue'] ?? false),
            'status' => (string) ($payload['status'] ?? FinanceBillingSchedule::STATUS_DRAFT),
            'notes' => $payload['notes'] ?? null,
            'items' => [[
                'product_name' => $contract->title,
                'description' => 'بند فوترة مرتبط بالعقد '.$contract->contract_number,
                'quantity' => 1,
                'unit_price' => $amount,
                'discount' => 0,
                'tax_rate' => $taxProfile['rate'],
                'tax_type' => $taxProfile['type'],
            ]],
        ], $actorUserId);
    }

    private function nextRunDate(FinanceBillingSchedule $schedule, Carbon $from): Carbon
    {
        $interval = max(1, (int) $schedule->interval_count);

        return match ($schedule->frequency) {
            'weekly' => $from->copy()->addWeeks($interval),
            'quarterly' => $from->copy()->addMonths(3 * $interval),
            'yearly' => $from->copy()->addYears($interval),
            'installment' => $from->copy()->addMonths($interval),
            default => $from->copy()->addMonths($interval),
        };
    }

    /**
     * @param  array<int, mixed>  $rawItems
     * @return array<int, array<string, mixed>>
     */
    private function normalizeItemSnapshot(
        array $rawItems,
        float $fallbackAmount,
        float $fallbackTaxRate,
        string $fallbackTaxType
    ): array {
        $items = [];
        foreach ($rawItems as $rawItem) {
            $name = trim((string) ($rawItem['product_name'] ?? $rawItem['title'] ?? ''));
            if ($name === '') {
                continue;
            }
            $qty = max(0.001, (float) ($rawItem['quantity'] ?? 1));
            $price = max(0.0, (float) ($rawItem['unit_price'] ?? 0));
            $items[] = [
                'product_name' => $name,
                'description' => $rawItem['description'] ?? null,
                'quantity' => $qty,
                'unit_price' => $price,
                'discount' => max(0, (float) ($rawItem['discount'] ?? 0)),
                'tax_rate' => (float) ($rawItem['tax_rate'] ?? $fallbackTaxRate),
                'tax_type' => (string) ($rawItem['tax_type'] ?? $fallbackTaxType),
                'total' => round($qty * $price, 2),
            ];
        }

        if ($items === [] && $fallbackAmount > 0) {
            $items[] = [
                'product_name' => 'بند فوترة مجدولة',
                'quantity' => 1,
                'unit_price' => $fallbackAmount,
                'discount' => 0,
                'tax_rate' => $fallbackTaxRate,
                'tax_type' => $fallbackTaxType,
                'total' => $fallbackAmount,
            ];
        }

        return $items;
    }

    private function contractBlocksBilling(FinanceBillingSchedule $schedule): bool
    {
        if (! $schedule->contract_id) {
            return false;
        }

        $contract = Contract::withoutGlobalScopes()->whereKey($schedule->contract_id)->first();
        if (! $contract) {
            return true;
        }

        if (in_array((string) $contract->status, ['closed', 'cancelled'], true)) {
            return true;
        }

        if ($contract->end_date && $contract->end_date->startOfDay()->lt(now()->startOfDay())) {
            return true;
        }

        return false;
    }

    private function occurrenceKey(FinanceBillingSchedule $schedule, string $occurrenceDate): string
    {
        return $schedule->id.'|'.$occurrenceDate;
    }

    private function advanceScheduleIfStillDue(FinanceBillingSchedule $locked, Carbon $onDate, string $occurrenceDate): void
    {
        $currentNext = $locked->next_run_on?->toDateString();
        if ($currentNext !== null && $currentNext !== $occurrenceDate) {
            return;
        }

        $generatedCount = (int) $locked->generated_count + 1;
        $completed = $locked->total_occurrences !== null && $generatedCount >= (int) $locked->total_occurrences;
        $locked->update([
            'generated_count' => $generatedCount,
            'next_run_on' => $completed ? null : $this->nextRunDate($locked, $onDate)->toDateString(),
            'status' => $completed ? FinanceBillingSchedule::STATUS_COMPLETED : FinanceBillingSchedule::STATUS_ACTIVE,
        ]);
    }
}
