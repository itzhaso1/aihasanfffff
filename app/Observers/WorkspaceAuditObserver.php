<?php

namespace App\Observers;

use App\Models\Finance\FinanceCreditNote;
use App\Models\Finance\FinanceInvoice;
use App\Models\Finance\FinanceInvoicePayment;
use App\Models\Finance\FinanceReceipt;
use App\Models\User;
use App\Services\Audit\AuditLogService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class WorkspaceAuditObserver
{
    public function __construct(private readonly AuditLogService $auditLogService) {}

    public function created(Model $model): void
    {
        $this->auditLogService->log(
            action: $this->domainAction($model, 'created'),
            entityType: $model::class,
            entityId: $model->getKey(),
            oldValues: null,
            newValues: $this->safeAttributes($model),
            actor: Auth::user() instanceof User ? Auth::user() : null,
            workspaceId: $this->resolveWorkspaceId($model),
        );
    }

    public function updated(Model $model): void
    {
        $changes = $model->getChanges();
        if ($changes === []) {
            return;
        }

        $oldValues = collect($changes)
            ->keys()
            ->mapWithKeys(fn (string $key): array => [$key => $model->getOriginal($key)])
            ->all();

        $this->auditLogService->log(
            action: $this->domainAction($model, 'updated'),
            entityType: $model::class,
            entityId: $model->getKey(),
            oldValues: $oldValues,
            newValues: $changes,
            actor: Auth::user() instanceof User ? Auth::user() : null,
            workspaceId: $this->resolveWorkspaceId($model),
        );
    }

    public function deleted(Model $model): void
    {
        $this->auditLogService->log(
            action: $this->domainAction($model, 'deleted'),
            entityType: $model::class,
            entityId: $model->getKey(),
            oldValues: $this->safeAttributes($model),
            newValues: null,
            actor: Auth::user() instanceof User ? Auth::user() : null,
            workspaceId: $this->resolveWorkspaceId($model),
        );
    }

    private function domainAction(Model $model, string $event): string
    {
        if ($model instanceof FinanceInvoice) {
            if ($event === 'created') {
                return 'invoice_created';
            }
            if ($event === 'updated') {
                $nextStatus = (string) $model->invoice_status;
                $previousStatus = (string) ($model->getOriginal('invoice_status') ?: '');
                if ($previousStatus !== 'issued' && $nextStatus === 'issued') {
                    return 'invoice_issued';
                }
                if ($previousStatus !== 'cancelled' && $nextStatus === 'cancelled') {
                    return 'invoice_cancelled';
                }
                if ($nextStatus === 'draft' || $previousStatus === 'draft') {
                    return 'invoice_updated_draft';
                }
            }
        }

        if ($model instanceof FinanceInvoicePayment) {
            if ($event === 'created') {
                return 'payment_added';
            }
            if ($event === 'updated' && (array_key_exists('reversed_at', $model->getChanges()) || ($model->status ?? null) === 'reversed')) {
                return 'payment_reversed';
            }
        }

        if ($model instanceof FinanceCreditNote && $event === 'created') {
            return $model->type === FinanceCreditNote::TYPE_DEBIT
                ? 'debit_note_created'
                : 'credit_note_created';
        }

        if ($model instanceof FinanceReceipt) {
            if ($event === 'created') {
                return 'receipt_created';
            }
            if ($event === 'updated' && (($model->status ?? null) === FinanceReceipt::STATUS_VOIDED)) {
                return 'receipt_voided';
            }
        }

        return $event;
    }

    private function safeAttributes(Model $model): array
    {
        return collect($model->attributesToArray())
            ->except(['password', 'remember_token', 'token', 'provider_payload'])
            ->all();
    }

    private function resolveWorkspaceId(Model $model): ?int
    {
        $workspaceId = $model->getAttribute('workspace_id');

        return $workspaceId === null ? null : (int) $workspaceId;
    }
}
