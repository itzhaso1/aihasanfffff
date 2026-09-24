<?php

namespace App\Services\Commerce\Intelligence;

use App\Enums\Commerce\CommerceProvenanceSource;
use App\Models\Commerce\CommerceCustomerContext;
use App\Models\Workspace;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Support\Collection;
use InvalidArgumentException;

final class CommerceCustomerContextService
{
    public function __construct(
        private readonly WorkspaceContext $workspaceContext,
    ) {}

    public function setContext(
        Workspace $workspace,
        ?int $customerId,
        ?string $sessionId,
        string $key,
        mixed $value,
        ?string $dataType = 'string',
        ?string $unit = null,
        CommerceProvenanceSource|string $source = CommerceProvenanceSource::CustomerExplicit,
        ?float $confidence = null,
    ): CommerceCustomerContext {
        if ($customerId === null && ($sessionId === null || trim($sessionId) === '')) {
            throw new InvalidArgumentException('Either customer ID or session ID must be provided for customer context.');
        }

        $key = strtolower(trim($key));
        if ($key === '' || ! preg_match('/^[a-z0-9_]{2,64}$/', $key)) {
            throw new InvalidArgumentException('Context key must be lowercase alphanumeric with underscores.');
        }

        $sourceEnum = $source instanceof CommerceProvenanceSource
            ? $source
            : (CommerceProvenanceSource::tryFrom((string) $source) ?? CommerceProvenanceSource::CustomerExplicit);

        return $this->workspaceContext->runFor($workspace, function () use (
            $workspace,
            $customerId,
            $sessionId,
            $key,
            $value,
            $dataType,
            $unit,
            $sourceEnum,
            $confidence,
        ): CommerceCustomerContext {
            $contextValue = is_array($value) ? json_encode($value, JSON_UNESCAPED_UNICODE) : (string) $value;

            return CommerceCustomerContext::query()->updateOrCreate(
                [
                    'workspace_id' => $workspace->id,
                    'customer_id' => $customerId,
                    'session_id' => $sessionId,
                    'context_key' => $key,
                ],
                [
                    'context_value' => $contextValue,
                    'data_type' => $dataType ?? 'string',
                    'unit' => $unit,
                    'source' => $sourceEnum->value,
                    'confidence' => $confidence ?? ($sourceEnum === CommerceProvenanceSource::CustomerExplicit ? 1.0 : 0.8),
                ],
            );
        });
    }

    public function getContext(
        Workspace $workspace,
        ?int $customerId,
        ?string $sessionId = null,
    ): Collection {
        if ($customerId === null && ($sessionId === null || trim((string) $sessionId) === '')) {
            return collect();
        }

        return $this->workspaceContext->runFor($workspace, function () use ($workspace, $customerId, $sessionId): Collection {
            return CommerceCustomerContext::query()
                ->where('workspace_id', $workspace->id)
                ->when($customerId, fn ($q) => $q->where('customer_id', $customerId))
                ->when($sessionId && ! $customerId, fn ($q) => $q->where('session_id', $sessionId))
                ->get();
        });
    }

    public function getContextAsArray(
        Workspace $workspace,
        ?int $customerId,
        ?string $sessionId = null,
    ): array {
        $items = $this->getContext($workspace, $customerId, $sessionId);
        $result = [];

        foreach ($items as $item) {
            $val = $item->context_value;
            if ($item->data_type === 'integer' || $item->data_type === 'decimal' || $item->data_type === 'measurement' || $item->data_type === 'dimension') {
                $val = is_numeric($val) ? (float) $val : $val;
            } elseif ($item->data_type === 'boolean') {
                $val = filter_var($val, FILTER_VALIDATE_BOOLEAN);
            } elseif ($item->data_type === 'json' || $item->data_type === 'multi_value') {
                $val = json_decode((string) $val, true) ?? $val;
            }

            $result[$item->context_key] = $val;
        }

        return $result;
    }
}
