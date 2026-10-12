<?php

namespace App\Services\Commerce\Intelligence;

use App\Enums\Commerce\CommerceAttributeDataType;
use App\Models\Commerce\CommerceRequirementDefinition;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Audit\AuditLogService;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use RuntimeException;

final class CommerceRequirementService
{
    public function __construct(
        private readonly AuditLogService $audit,
        private readonly WorkspaceContext $workspaceContext,
    ) {}

    public function createDefinition(
        Workspace $workspace,
        array $data,
        ?User $actor = null,
    ): CommerceRequirementDefinition {
        return $this->workspaceContext->runFor($workspace, function () use ($workspace, $data, $actor): CommerceRequirementDefinition {
            $key = strtolower(trim((string) ($data['key'] ?? '')));
            if ($key === '' || ! preg_match('/^[a-z0-9_]{2,64}$/', $key)) {
                throw new InvalidArgumentException('Requirement key must be lowercase alphanumeric with underscores (2-64 chars).');
            }

            if (CommerceRequirementDefinition::query()->where('workspace_id', $workspace->id)->where('key', $key)->exists()) {
                throw new InvalidArgumentException("Requirement key '{$key}' already exists in this workspace.");
            }

            $dataType = $data['data_type'] instanceof CommerceAttributeDataType
                ? $data['data_type']
                : (CommerceAttributeDataType::tryFrom((string) ($data['data_type'] ?? '')) ?? CommerceAttributeDataType::String);

            $requirement = CommerceRequirementDefinition::query()->create([
                'workspace_id' => $workspace->id,
                'key' => $key,
                'label' => trim((string) ($data['label'] ?? $key)),
                'description' => $data['description'] ?? null,
                'data_type' => $dataType,
                'unit' => $data['unit'] ?? null,
                'is_required' => (bool) ($data['is_required'] ?? true),
                'validation_rules' => $data['validation_rules'] ?? null,
                'applicability' => $data['applicability'] ?? null,
                'is_active' => (bool) ($data['is_active'] ?? true),
            ]);

            $this->audit->log(
                action: 'commerce.intelligence.requirement_definition_created',
                entityType: CommerceRequirementDefinition::class,
                entityId: (int) $requirement->id,
                actor: $actor,
                meta: ['key' => $requirement->key],
                workspaceId: (int) $workspace->id,
            );

            return $requirement;
        });
    }

    public function updateDefinition(
        Workspace $workspace,
        CommerceRequirementDefinition $requirement,
        array $data,
        ?User $actor = null,
    ): CommerceRequirementDefinition {
        return $this->workspaceContext->runFor($workspace, function () use ($workspace, $requirement, $data, $actor): CommerceRequirementDefinition {
            if ((int) $requirement->workspace_id !== (int) $workspace->id) {
                throw new RuntimeException('Requirement definition workspace mismatch.');
            }

            $dataType = isset($data['data_type'])
                ? ($data['data_type'] instanceof CommerceAttributeDataType ? $data['data_type'] : CommerceAttributeDataType::tryFrom((string) $data['data_type']))
                : $requirement->data_type;

            $requirement->update([
                'label' => isset($data['label']) ? trim((string) $data['label']) : $requirement->label,
                'description' => array_key_exists('description', $data) ? $data['description'] : $requirement->description,
                'data_type' => $dataType ?? $requirement->data_type,
                'unit' => array_key_exists('unit', $data) ? $data['unit'] : $requirement->unit,
                'is_required' => isset($data['is_required']) ? (bool) $data['is_required'] : $requirement->is_required,
                'validation_rules' => array_key_exists('validation_rules', $data) ? $data['validation_rules'] : $requirement->validation_rules,
                'applicability' => array_key_exists('applicability', $data) ? $data['applicability'] : $requirement->applicability,
                'is_active' => isset($data['is_active']) ? (bool) $data['is_active'] : $requirement->is_active,
            ]);

            $this->audit->log(
                action: 'commerce.intelligence.requirement_definition_updated',
                entityType: CommerceRequirementDefinition::class,
                entityId: (int) $requirement->id,
                actor: $actor,
                meta: ['key' => $requirement->key],
                workspaceId: (int) $workspace->id,
            );

            return $requirement->fresh() ?? $requirement;
        });
    }

    public function deleteDefinition(
        Workspace $workspace,
        CommerceRequirementDefinition $requirement,
        ?User $actor = null,
    ): void {
        $this->workspaceContext->runFor($workspace, function () use ($workspace, $requirement, $actor): void {
            if ((int) $requirement->workspace_id !== (int) $workspace->id) {
                throw new RuntimeException('Requirement definition workspace mismatch.');
            }

            $key = $requirement->key;
            $id = $requirement->id;
            $requirement->delete();

            $this->audit->log(
                action: 'commerce.intelligence.requirement_definition_deleted',
                entityType: CommerceRequirementDefinition::class,
                entityId: (int) $id,
                actor: $actor,
                meta: ['key' => $key],
                workspaceId: (int) $workspace->id,
            );
        });
    }

    public function listActiveRequirements(
        Workspace $workspace,
        ?string $domain = null,
        ?int $categoryId = null,
        ?int $itemId = null,
    ): Collection {
        return $this->workspaceContext->runFor($workspace, function () use ($workspace, $domain, $categoryId, $itemId): Collection {
            return CommerceRequirementDefinition::query()
                ->where('workspace_id', $workspace->id)
                ->where('is_active', true)
                ->get()
                ->filter(function (CommerceRequirementDefinition $req) use ($domain, $categoryId, $itemId): bool {
                    $app = $req->applicability;
                    if (! $app) {
                        return true;
                    }
                    if ($domain !== null && isset($app['domain']) && $app['domain'] !== $domain) {
                        return false;
                    }
                    if ($categoryId !== null && isset($app['category_id']) && (int) $app['category_id'] !== $categoryId) {
                        return false;
                    }
                    if ($itemId !== null && isset($app['item_id']) && (int) $app['item_id'] !== $itemId) {
                        return false;
                    }

                    return true;
                })
                ->values();
        });
    }
}
