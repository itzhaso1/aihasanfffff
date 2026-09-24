<?php

namespace App\Services\Commerce\Intelligence;

use App\Enums\Commerce\CommerceRuleType;
use App\Models\Commerce\CommerceIntelligenceRule;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Audit\AuditLogService;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use RuntimeException;

final class CommerceRuleService
{
    public function __construct(
        private readonly AuditLogService $audit,
        private readonly WorkspaceContext $workspaceContext,
    ) {}

    public function createRule(
        Workspace $workspace,
        array $data,
        ?User $actor = null,
    ): CommerceIntelligenceRule {
        return $this->workspaceContext->runFor($workspace, function () use ($workspace, $data, $actor): CommerceIntelligenceRule {
            $name = trim((string) ($data['name'] ?? ''));
            if ($name === '') {
                throw new InvalidArgumentException('Rule name is required.');
            }

            $ruleType = $data['rule_type'] instanceof CommerceRuleType
                ? $data['rule_type']
                : (CommerceRuleType::tryFrom((string) ($data['rule_type'] ?? '')) ?? CommerceRuleType::RecommendationConstraint);

            $conditions = $data['conditions'] ?? [];
            if (! is_array($conditions)) {
                throw new InvalidArgumentException('Rule conditions must be a structured array.');
            }

            $actions = $data['actions'] ?? [];
            if (! is_array($actions)) {
                throw new InvalidArgumentException('Rule actions must be a structured array.');
            }

            $rule = CommerceIntelligenceRule::query()->create([
                'workspace_id' => $workspace->id,
                'name' => $name,
                'rule_type' => $ruleType,
                'target_entity_type' => $data['target_entity_type'] ?? null,
                'target_entity_id' => isset($data['target_entity_id']) ? (int) $data['target_entity_id'] : null,
                'category_id' => isset($data['category_id']) ? (int) $data['category_id'] : null,
                'conditions' => $conditions,
                'actions' => $actions,
                'priority' => (int) ($data['priority'] ?? 0),
                'is_active' => (bool) ($data['is_active'] ?? true),
            ]);

            $this->audit->log(
                action: 'commerce.intelligence.rule_created',
                entityType: CommerceIntelligenceRule::class,
                entityId: (int) $rule->id,
                actor: $actor,
                meta: ['name' => $name, 'rule_type' => $ruleType->value],
                workspaceId: (int) $workspace->id,
            );

            return $rule;
        });
    }

    public function updateRule(
        Workspace $workspace,
        CommerceIntelligenceRule $rule,
        array $data,
        ?User $actor = null,
    ): CommerceIntelligenceRule {
        return $this->workspaceContext->runFor($workspace, function () use ($workspace, $rule, $data, $actor): CommerceIntelligenceRule {
            if ((int) $rule->workspace_id !== (int) $workspace->id) {
                throw new RuntimeException('Rule workspace mismatch.');
            }

            $ruleType = isset($data['rule_type'])
                ? ($data['rule_type'] instanceof CommerceRuleType ? $data['rule_type'] : CommerceRuleType::tryFrom((string) $data['rule_type']))
                : $rule->rule_type;

            $rule->update([
                'name' => isset($data['name']) ? trim((string) $data['name']) : $rule->name,
                'rule_type' => $ruleType ?? $rule->rule_type,
                'target_entity_type' => array_key_exists('target_entity_type', $data) ? $data['target_entity_type'] : $rule->target_entity_type,
                'target_entity_id' => array_key_exists('target_entity_id', $data) ? (isset($data['target_entity_id']) ? (int) $data['target_entity_id'] : null) : $rule->target_entity_id,
                'category_id' => array_key_exists('category_id', $data) ? (isset($data['category_id']) ? (int) $data['category_id'] : null) : $rule->category_id,
                'conditions' => isset($data['conditions']) && is_array($data['conditions']) ? $data['conditions'] : $rule->conditions,
                'actions' => isset($data['actions']) && is_array($data['actions']) ? $data['actions'] : $rule->actions,
                'priority' => isset($data['priority']) ? (int) $data['priority'] : $rule->priority,
                'is_active' => isset($data['is_active']) ? (bool) $data['is_active'] : $rule->is_active,
            ]);

            $this->audit->log(
                action: 'commerce.intelligence.rule_updated',
                entityType: CommerceIntelligenceRule::class,
                entityId: (int) $rule->id,
                actor: $actor,
                meta: ['name' => $rule->name],
                workspaceId: (int) $workspace->id,
            );

            return $rule->fresh() ?? $rule;
        });
    }

    public function deleteRule(
        Workspace $workspace,
        CommerceIntelligenceRule $rule,
        ?User $actor = null,
    ): void {
        $this->workspaceContext->runFor($workspace, function () use ($workspace, $rule, $actor): void {
            if ((int) $rule->workspace_id !== (int) $workspace->id) {
                throw new RuntimeException('Rule workspace mismatch.');
            }

            $id = $rule->id;
            $name = $rule->name;
            $rule->delete();

            $this->audit->log(
                action: 'commerce.intelligence.rule_deleted',
                entityType: CommerceIntelligenceRule::class,
                entityId: (int) $id,
                actor: $actor,
                meta: ['name' => $name],
                workspaceId: (int) $workspace->id,
            );
        });
    }

    public function listActiveRules(
        Workspace $workspace,
        ?string $ruleType = null,
        ?int $categoryId = null,
        ?int $entityId = null,
    ): Collection {
        return $this->workspaceContext->runFor($workspace, function () use ($workspace, $ruleType, $categoryId, $entityId): Collection {
            return CommerceIntelligenceRule::query()
                ->where('workspace_id', $workspace->id)
                ->where('is_active', true)
                ->when($ruleType, fn ($q) => $q->where('rule_type', $ruleType))
                ->when($categoryId, fn ($q) => $q->where(fn ($sub) => $sub->whereNull('category_id')->orWhere('category_id', $categoryId)))
                ->when($entityId, fn ($q) => $q->where(fn ($sub) => $sub->whereNull('target_entity_id')->orWhere('target_entity_id', $entityId)))
                ->orderByDesc('priority')
                ->get();
        });
    }

    public function evaluateRules(Collection $rules, array $evalContext): array
    {
        $triggeredRules = [];
        $messages = [];
        $constraints = [];

        foreach ($rules as $rule) {
            if ($this->evaluateConditions($rule->conditions, $evalContext)) {
                $triggeredRules[] = $rule;
                if (isset($rule->actions['message'])) {
                    $messages[] = (string) $rule->actions['message'];
                }
                if (isset($rule->actions['constraint']) && is_array($rule->actions['constraint'])) {
                    $constraints[] = $rule->actions['constraint'];
                }
            }
        }

        return [
            'triggered_rules' => $triggeredRules,
            'messages' => $messages,
            'constraints' => $constraints,
        ];
    }

    private function evaluateConditions(array $conditions, array $evalContext): bool
    {
        if ($conditions === []) {
            return true;
        }

        foreach ($conditions as $cond) {
            $field = $cond['field'] ?? ($cond['attribute_key'] ?? null);
            $operator = $cond['operator'] ?? '==';
            $expected = $cond['value'] ?? null;

            if ($field === null || ! array_key_exists($field, $evalContext)) {
                return false;
            }

            $actual = $evalContext[$field];

            if (! $this->compare($actual, $operator, $expected)) {
                return false;
            }
        }

        return true;
    }

    private function compare(mixed $actual, string $operator, mixed $expected): bool
    {
        return match ($operator) {
            '==', '=' => (string) $actual === (string) $expected,
            '!=', '<>' => (string) $actual !== (string) $expected,
            '>' => is_numeric($actual) && is_numeric($expected) && (float) $actual > (float) $expected,
            '>=' => is_numeric($actual) && is_numeric($expected) && (float) $actual >= (float) $expected,
            '<' => is_numeric($actual) && is_numeric($expected) && (float) $actual < (float) $expected,
            '<=' => is_numeric($actual) && is_numeric($expected) && (float) $actual <= (float) $expected,
            'in' => is_array($expected) && in_array($actual, $expected, true),
            'not_in' => is_array($expected) && ! in_array($actual, $expected, true),
            'contains' => str_contains((string) $actual, (string) $expected),
            default => false,
        };
    }
}
