<?php

namespace App\Services\Commerce\Intelligence;

use App\Enums\Commerce\CommerceCompatibilityType;
use App\Enums\Commerce\CommerceProvenanceSource;
use App\Models\Commerce\CommerceCompatibility;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Audit\AuditLogService;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use RuntimeException;

final class CommerceCompatibilityService
{
    public function __construct(
        private readonly AuditLogService $audit,
        private readonly WorkspaceContext $workspaceContext,
    ) {}

    public function createCompatibility(
        Workspace $workspace,
        array $data,
        ?User $actor = null,
    ): CommerceCompatibility {
        return $this->workspaceContext->runFor($workspace, function () use ($workspace, $data, $actor): CommerceCompatibility {
            $sourceType = (string) ($data['source_entity_type'] ?? 'chat_catalog_item');
            $sourceId = (int) ($data['source_entity_id'] ?? 0);
            if ($sourceId <= 0) {
                throw new InvalidArgumentException('Source entity ID is required.');
            }

            $compatType = $data['compatibility_type'] instanceof CommerceCompatibilityType
                ? $data['compatibility_type']
                : (CommerceCompatibilityType::tryFrom((string) ($data['compatibility_type'] ?? '')) ?? CommerceCompatibilityType::Compatible);

            $provenanceInput = $data['provenance'] ?? null;
            $provenance = $provenanceInput instanceof CommerceProvenanceSource
                ? $provenanceInput
                : (CommerceProvenanceSource::tryFrom((string) $provenanceInput) ?? CommerceProvenanceSource::MerchantProvided);

            $compatibility = CommerceCompatibility::query()->create([
                'workspace_id' => $workspace->id,
                'source_entity_type' => $sourceType,
                'source_entity_id' => $sourceId,
                'target_type' => (string) ($data['target_type'] ?? 'entity'),
                'target_entity_type' => $data['target_entity_type'] ?? null,
                'target_entity_id' => isset($data['target_entity_id']) ? (int) $data['target_entity_id'] : null,
                'target_reference' => $data['target_reference'] ?? null,
                'compatibility_type' => $compatType,
                'constraints' => $data['constraints'] ?? null,
                'status' => (string) ($data['status'] ?? 'active'),
                'provenance' => $provenance,
            ]);

            $this->audit->log(
                action: 'commerce.intelligence.compatibility_created',
                entityType: CommerceCompatibility::class,
                entityId: (int) $compatibility->id,
                actor: $actor,
                meta: [
                    'source_entity' => "{$sourceType}:{$sourceId}",
                    'compatibility_type' => $compatType->value,
                ],
                workspaceId: (int) $workspace->id,
            );

            return $compatibility;
        });
    }

    public function deleteCompatibility(
        Workspace $workspace,
        CommerceCompatibility $compatibility,
        ?User $actor = null,
    ): void {
        $this->workspaceContext->runFor($workspace, function () use ($workspace, $compatibility, $actor): void {
            if ((int) $compatibility->workspace_id !== (int) $workspace->id) {
                throw new RuntimeException('Compatibility workspace mismatch.');
            }

            $id = $compatibility->id;
            $compatibility->delete();

            $this->audit->log(
                action: 'commerce.intelligence.compatibility_deleted',
                entityType: CommerceCompatibility::class,
                entityId: (int) $id,
                actor: $actor,
                meta: ['id' => $id],
                workspaceId: (int) $workspace->id,
            );
        });
    }

    public function listCompatibilitiesForEntity(
        Workspace $workspace,
        string $sourceType,
        int $sourceId,
    ): Collection {
        return $this->workspaceContext->runFor($workspace, function () use ($workspace, $sourceType, $sourceId): Collection {
            return CommerceCompatibility::query()
                ->where('workspace_id', $workspace->id)
                ->where('source_entity_type', $sourceType)
                ->where('source_entity_id', $sourceId)
                ->where('status', 'active')
                ->get();
        });
    }

    public function evaluateCompatibility(
        Workspace $workspace,
        string $sourceType,
        int $sourceId,
        array $targetContext,
    ): array {
        $compatibilities = $this->listCompatibilitiesForEntity($workspace, $sourceType, $sourceId);
        if ($compatibilities->isEmpty()) {
            return [
                'has_compatibility_data' => false,
                'is_compatible' => null,
                'matched_rule' => null,
            ];
        }

        foreach ($compatibilities as $compat) {
            if ($this->matchesTargetContext($compat, $targetContext)) {
                $isCompatible = $compat->compatibility_type === CommerceCompatibilityType::Compatible
                    || $compat->compatibility_type === CommerceCompatibilityType::Conditional;

                return [
                    'has_compatibility_data' => true,
                    'is_compatible' => $isCompatible,
                    'compatibility_type' => $compat->compatibility_type->value,
                    'matched_rule' => $compat,
                ];
            }
        }

        return [
            'has_compatibility_data' => true,
            'is_compatible' => false,
            'compatibility_type' => CommerceCompatibilityType::Incompatible->value,
            'matched_rule' => null,
        ];
    }

    private function matchesTargetContext(CommerceCompatibility $compat, array $context): bool
    {
        if ($compat->target_entity_id !== null && isset($context['target_entity_id'])) {
            return (int) $compat->target_entity_id === (int) $context['target_entity_id'];
        }

        if ($compat->target_reference !== null) {
            foreach ($compat->target_reference as $key => $expectedValue) {
                if (! isset($context[$key])) {
                    return false;
                }
                if (is_numeric($expectedValue) && is_numeric($context[$key])) {
                    if ((float) $expectedValue !== (float) $context[$key]) {
                        return false;
                    }
                } elseif (strcasecmp((string) $expectedValue, (string) $context[$key]) !== 0) {
                    return false;
                }
            }

            return true;
        }

        return false;
    }
}
