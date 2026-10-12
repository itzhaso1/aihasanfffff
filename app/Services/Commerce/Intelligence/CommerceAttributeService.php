<?php

namespace App\Services\Commerce\Intelligence;

use App\Enums\Commerce\CommerceAttributeDataType;
use App\Enums\Commerce\CommerceProvenanceSource;
use App\Models\Commerce\CommerceAttributeDefinition;
use App\Models\Commerce\CommerceEntityAttributeValue;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Audit\AuditLogService;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use RuntimeException;

final class CommerceAttributeService
{
    public function __construct(
        private readonly AuditLogService $audit,
        private readonly WorkspaceContext $workspaceContext,
    ) {}

    public function createDefinition(
        Workspace $workspace,
        array $data,
        ?User $actor = null,
    ): CommerceAttributeDefinition {
        return $this->workspaceContext->runFor($workspace, function () use ($workspace, $data, $actor): CommerceAttributeDefinition {
            $key = strtolower(trim((string) ($data['key'] ?? '')));
            if ($key === '' || ! preg_match('/^[a-z0-9_]{2,64}$/', $key)) {
                throw new InvalidArgumentException('Attribute key must be lowercase alphanumeric with underscores (2-64 chars).');
            }

            if (CommerceAttributeDefinition::query()->where('workspace_id', $workspace->id)->where('key', $key)->exists()) {
                throw new InvalidArgumentException("Attribute definition key '{$key}' already exists in this workspace.");
            }

            $dataType = $data['data_type'] instanceof CommerceAttributeDataType
                ? $data['data_type']
                : (CommerceAttributeDataType::tryFrom((string) ($data['data_type'] ?? '')) ?? CommerceAttributeDataType::String);

            $definition = CommerceAttributeDefinition::query()->create([
                'workspace_id' => $workspace->id,
                'key' => $key,
                'display_name' => trim((string) ($data['display_name'] ?? $key)),
                'description' => $data['description'] ?? null,
                'data_type' => $dataType,
                'unit' => $data['unit'] ?? null,
                'is_required' => (bool) ($data['is_required'] ?? false),
                'is_searchable' => (bool) ($data['is_searchable'] ?? true),
                'is_filterable' => (bool) ($data['is_filterable'] ?? true),
                'is_customer_visible' => (bool) ($data['is_customer_visible'] ?? true),
                'is_ai_readable' => (bool) ($data['is_ai_readable'] ?? true),
                'validation_constraints' => $data['validation_constraints'] ?? null,
                'enum_options' => $data['enum_options'] ?? null,
                'is_active' => (bool) ($data['is_active'] ?? true),
                'sort_order' => (int) ($data['sort_order'] ?? 0),
            ]);

            $this->audit->log(
                action: 'commerce.intelligence.attribute_definition_created',
                entityType: CommerceAttributeDefinition::class,
                entityId: (int) $definition->id,
                actor: $actor,
                meta: ['key' => $definition->key, 'data_type' => $definition->data_type->value],
                workspaceId: (int) $workspace->id,
            );

            return $definition;
        });
    }

    public function updateDefinition(
        Workspace $workspace,
        CommerceAttributeDefinition $definition,
        array $data,
        ?User $actor = null,
    ): CommerceAttributeDefinition {
        return $this->workspaceContext->runFor($workspace, function () use ($workspace, $definition, $data, $actor): CommerceAttributeDefinition {
            if ((int) $definition->workspace_id !== (int) $workspace->id) {
                throw new RuntimeException('Attribute definition workspace mismatch.');
            }

            $dataType = isset($data['data_type'])
                ? ($data['data_type'] instanceof CommerceAttributeDataType ? $data['data_type'] : CommerceAttributeDataType::tryFrom((string) $data['data_type']))
                : $definition->data_type;

            $definition->update([
                'display_name' => isset($data['display_name']) ? trim((string) $data['display_name']) : $definition->display_name,
                'description' => array_key_exists('description', $data) ? $data['description'] : $definition->description,
                'data_type' => $dataType ?? $definition->data_type,
                'unit' => array_key_exists('unit', $data) ? $data['unit'] : $definition->unit,
                'is_required' => isset($data['is_required']) ? (bool) $data['is_required'] : $definition->is_required,
                'is_searchable' => isset($data['is_searchable']) ? (bool) $data['is_searchable'] : $definition->is_searchable,
                'is_filterable' => isset($data['is_filterable']) ? (bool) $data['is_filterable'] : $definition->is_filterable,
                'is_customer_visible' => isset($data['is_customer_visible']) ? (bool) $data['is_customer_visible'] : $definition->is_customer_visible,
                'is_ai_readable' => isset($data['is_ai_readable']) ? (bool) $data['is_ai_readable'] : $definition->is_ai_readable,
                'validation_constraints' => array_key_exists('validation_constraints', $data) ? $data['validation_constraints'] : $definition->validation_constraints,
                'enum_options' => array_key_exists('enum_options', $data) ? $data['enum_options'] : $definition->enum_options,
                'is_active' => isset($data['is_active']) ? (bool) $data['is_active'] : $definition->is_active,
                'sort_order' => isset($data['sort_order']) ? (int) $data['sort_order'] : $definition->sort_order,
            ]);

            $this->audit->log(
                action: 'commerce.intelligence.attribute_definition_updated',
                entityType: CommerceAttributeDefinition::class,
                entityId: (int) $definition->id,
                actor: $actor,
                meta: ['key' => $definition->key],
                workspaceId: (int) $workspace->id,
            );

            return $definition->fresh() ?? $definition;
        });
    }

    public function deleteDefinition(
        Workspace $workspace,
        CommerceAttributeDefinition $definition,
        ?User $actor = null,
    ): void {
        $this->workspaceContext->runFor($workspace, function () use ($workspace, $definition, $actor): void {
            if ((int) $definition->workspace_id !== (int) $workspace->id) {
                throw new RuntimeException('Attribute definition workspace mismatch.');
            }

            $key = $definition->key;
            $id = $definition->id;
            $definition->delete();

            $this->audit->log(
                action: 'commerce.intelligence.attribute_definition_deleted',
                entityType: CommerceAttributeDefinition::class,
                entityId: (int) $id,
                actor: $actor,
                meta: ['key' => $key],
                workspaceId: (int) $workspace->id,
            );
        });
    }

    public function setEntityValue(
        Workspace $workspace,
        string $entityType,
        int $entityId,
        CommerceAttributeDefinition $definition,
        mixed $rawInput,
        ?string $unit = null,
        CommerceProvenanceSource|string $source = CommerceProvenanceSource::MerchantProvided,
        ?float $confidence = null,
        ?User $actor = null,
    ): CommerceEntityAttributeValue {
        return $this->workspaceContext->runFor($workspace, function () use (
            $workspace,
            $entityType,
            $entityId,
            $definition,
            $rawInput,
            $unit,
            $source,
            $confidence,
            $actor,
        ): CommerceEntityAttributeValue {
            if ((int) $definition->workspace_id !== (int) $workspace->id) {
                throw new RuntimeException('Attribute definition workspace mismatch.');
            }

            $sourceEnum = $source instanceof CommerceProvenanceSource
                ? $source
                : (CommerceProvenanceSource::tryFrom((string) $source) ?? CommerceProvenanceSource::MerchantProvided);

            // Validation: non-authoritative values (customer/ai) cannot overwrite authoritative values
            if (! $sourceEnum->isAuthoritative()) {
                $hasAuthoritative = CommerceEntityAttributeValue::query()
                    ->where('workspace_id', $workspace->id)
                    ->where('entity_type', $entityType)
                    ->where('entity_id', $entityId)
                    ->where('commerce_attribute_definition_id', $definition->id)
                    ->whereIn('source', [CommerceProvenanceSource::MerchantProvided->value, CommerceProvenanceSource::MerchantRule->value])
                    ->exists();

                if ($hasAuthoritative) {
                    throw new InvalidArgumentException("Cannot overwrite merchant-provided authoritative attribute '{$definition->key}' with non-authoritative source '{$sourceEnum->value}'.");
                }
            }

            $parsed = $this->parseAndValidateValue($definition, $rawInput);

            $value = CommerceEntityAttributeValue::query()->updateOrCreate(
                [
                    'workspace_id' => $workspace->id,
                    'entity_type' => $entityType,
                    'entity_id' => $entityId,
                    'commerce_attribute_definition_id' => $definition->id,
                    'source' => $sourceEnum->value,
                ],
                [
                    'value_string' => $parsed['string'],
                    'value_number' => $parsed['number'],
                    'value_boolean' => $parsed['boolean'],
                    'value_json' => $parsed['json'],
                    'unit' => $unit ?? $definition->unit,
                    'confidence' => $sourceEnum->isAuthoritative() ? 1.0 : ($confidence ?? 0.8),
                ],
            );

            $this->audit->log(
                action: 'commerce.intelligence.attribute_value_set',
                entityType: CommerceEntityAttributeValue::class,
                entityId: (int) $value->id,
                actor: $actor,
                meta: [
                    'key' => $definition->key,
                    'entity_type' => $entityType,
                    'entity_id' => $entityId,
                    'source' => $sourceEnum->value,
                ],
                workspaceId: (int) $workspace->id,
            );

            return $value;
        });
    }

    public function getEntityAttributeValues(
        Workspace $workspace,
        string $entityType,
        int $entityId,
    ): Collection {
        return $this->workspaceContext->runFor($workspace, function () use ($workspace, $entityType, $entityId): Collection {
            return CommerceEntityAttributeValue::query()
                ->with('definition')
                ->where('workspace_id', $workspace->id)
                ->where('entity_type', $entityType)
                ->where('entity_id', $entityId)
                ->get();
        });
    }

    private function parseAndValidateValue(CommerceAttributeDefinition $definition, mixed $input): array
    {
        if ($input === null || $input === '') {
            if ($definition->is_required) {
                throw new InvalidArgumentException("Attribute '{$definition->display_name}' is required.");
            }

            return ['string' => null, 'number' => null, 'boolean' => null, 'json' => null];
        }

        $type = $definition->data_type->value;
        $constraints = $definition->validation_constraints ?? [];
        $enumOptions = $definition->enum_options ?? [];

        switch ($type) {
            case 'integer':
            case 'decimal':
            case 'measurement':
            case 'dimension':
                if (! is_numeric($input)) {
                    throw new InvalidArgumentException("Attribute '{$definition->display_name}' must be numeric.");
                }
                $num = (float) $input;
                if (isset($constraints['min']) && $num < (float) $constraints['min']) {
                    throw new InvalidArgumentException("Attribute '{$definition->display_name}' must be at least {$constraints['min']}.");
                }
                if (isset($constraints['max']) && $num > (float) $constraints['max']) {
                    throw new InvalidArgumentException("Attribute '{$definition->display_name}' must be at most {$constraints['max']}.");
                }

                return [
                    'string' => (string) $num,
                    'number' => $num,
                    'boolean' => null,
                    'json' => null,
                ];

            case 'boolean':
                $bool = filter_var($input, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                if ($bool === null) {
                    throw new InvalidArgumentException("Attribute '{$definition->display_name}' must be a boolean.");
                }

                return [
                    'string' => $bool ? 'true' : 'false',
                    'number' => null,
                    'boolean' => $bool,
                    'json' => null,
                ];

            case 'enum':
                $str = (string) $input;
                if ($enumOptions !== [] && ! in_array($str, $enumOptions, true)) {
                    $allowed = implode(', ', $enumOptions);
                    throw new InvalidArgumentException("Attribute '{$definition->display_name}' value must be one of: {$allowed}.");
                }

                return [
                    'string' => $str,
                    'number' => null,
                    'boolean' => null,
                    'json' => null,
                ];

            case 'json':
            case 'multi_value':
            case 'range':
            case 'reference':
                $json = is_array($input) ? $input : json_decode((string) $input, true);
                if (! is_array($json)) {
                    throw new InvalidArgumentException("Attribute '{$definition->display_name}' must be valid structured data.");
                }

                return [
                    'string' => json_encode($json, JSON_UNESCAPED_UNICODE),
                    'number' => null,
                    'boolean' => null,
                    'json' => $json,
                ];

            default:
                $str = (string) $input;
                if (isset($constraints['regex']) && ! preg_match($constraints['regex'], $str)) {
                    throw new InvalidArgumentException("Attribute '{$definition->display_name}' format is invalid.");
                }

                return [
                    'string' => $str,
                    'number' => null,
                    'boolean' => null,
                    'json' => null,
                ];
        }
    }
}
