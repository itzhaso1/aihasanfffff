<?php

namespace App\Observers;

use App\Models\Commerce\Seller;
use App\Models\Commerce\SellerPaymentAccount;
use App\Models\User;
use App\Services\Audit\AuditLogService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * Audit log for Commerce seller entities; redacts provider secrets from metadata.
 */
final class CommerceSensitiveAuditObserver
{
    public function __construct(private readonly AuditLogService $auditLogService) {}

    public function created(Model $model): void
    {
        $this->log('created', $model, null, $this->safeAttributes($model));
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

        $this->log('updated', $model, $this->redact($oldValues), $this->redact($changes));
    }

    public function deleted(Model $model): void
    {
        $this->log('deleted', $model, $this->safeAttributes($model), null);
    }

    private function log(string $event, Model $model, ?array $oldValues, ?array $newValues): void
    {
        $entityType = match (true) {
            $model instanceof Seller => 'commerce_seller',
            $model instanceof SellerPaymentAccount => 'commerce_seller_payment_account',
            default => $model::class,
        };

        $this->auditLogService->log(
            action: $entityType.'_'.$event,
            entityType: $model::class,
            entityId: $model->getKey(),
            oldValues: $oldValues,
            newValues: $newValues,
            actor: Auth::user() instanceof User ? Auth::user() : null,
            workspaceId: $model->getAttribute('workspace_id') !== null ? (int) $model->getAttribute('workspace_id') : null,
            meta: $model instanceof SellerPaymentAccount ? ['commerce_seller_id' => $model->commerce_seller_id] : null,
        );
    }

    private function safeAttributes(Model $model): array
    {
        return $this->redact($model->attributesToArray());
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function redact(array $values): array
    {
        unset($values['password'], $values['remember_token'], $values['provider_payload']);

        if (isset($values['metadata']) && is_array($values['metadata'])) {
            $values['metadata'] = collect($values['metadata'])
                ->except(['api_key', 'secret', 'token', 'credentials', 'webhook_secret'])
                ->all();
        }

        return $values;
    }
}
