<?php

namespace App\Services\Chat;

use App\Enums\ChatCatalog\ChatCatalogProductTemplateFieldLevel;
use App\Enums\ChatCatalog\ChatCatalogProductTemplateFieldType;
use App\Enums\ChatCatalog\ChatCatalogProductTemplateStatus;
use App\Models\ChatCatalogItem;
use App\Models\ChatCatalogProductTemplate;
use App\Models\ChatCatalogProductTemplateField;
use App\Models\Workspace;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final class ProductTemplateService
{
    public function __construct(
        private readonly ChatCatalogDataSanitizer $sanitizer,
        private readonly ProductTemplateValidationService $validation,
    ) {}

    /**
     * @return Collection<int, ChatCatalogProductTemplate>
     */
    public function listAvailableForWorkspace(Workspace $workspace): Collection
    {
        return ChatCatalogProductTemplate::query()
            ->accessibleByWorkspace($workspace->id)
            ->active()
            ->with('fields')
            ->orderByDesc('is_system')
            ->orderBy('name')
            ->get();
    }

    public function resolveForWorkspace(int $templateId, Workspace $workspace): ChatCatalogProductTemplate
    {
        $template = ChatCatalogProductTemplate::query()
            ->accessibleByWorkspace($workspace->id)
            ->with('fields')
            ->find($templateId);

        if (! $template) {
            throw ValidationException::withMessages([
                'product_template_id' => 'Product template not found for this workspace.',
            ]);
        }

        return $template;
    }

    public function assignToItem(ChatCatalogItem $item, ChatCatalogProductTemplate $template): ChatCatalogItem
    {
        if (! $template->isAccessibleByWorkspace((int) $item->workspace_id)) {
            throw ValidationException::withMessages([
                'product_template_id' => 'Template is not available for this workspace.',
            ]);
        }

        $item->update([
            'product_template_id' => $template->id,
            'product_template_schema_version' => (int) $template->schema_version,
        ]);

        if ($template->classification_hint !== null && $item->classification === null) {
            $item->update(['classification' => $template->classification_hint]);
        }

        return $item->refresh();
    }

    public function clearTemplate(ChatCatalogItem $item): ChatCatalogItem
    {
        $item->update([
            'product_template_id' => null,
            'product_template_schema_version' => null,
        ]);

        return $item->refresh();
    }

    public function cloneSystemTemplate(Workspace $workspace, ChatCatalogProductTemplate $source, string $name): ChatCatalogProductTemplate
    {
        if (! $source->is_system || $source->workspace_id !== null) {
            throw new InvalidArgumentException('Only system templates can be cloned with this method.');
        }

        if (! $source->isAccessibleByWorkspace($workspace->id)) {
            throw ValidationException::withMessages(['template' => 'Template is not accessible.']);
        }

        return DB::transaction(function () use ($workspace, $source, $name): ChatCatalogProductTemplate {
            $slug = $this->uniqueWorkspaceSlug($workspace, Str::slug($name));

            $clone = ChatCatalogProductTemplate::query()->create([
                'workspace_id' => $workspace->id,
                'slug' => $slug,
                'name' => $this->sanitizer->plainText($name, 255) ?? $name,
                'classification_hint' => $source->classification_hint,
                'status' => ChatCatalogProductTemplateStatus::Active,
                'schema_version' => (int) $source->schema_version,
                'is_system' => false,
                'source_template_id' => $source->id,
            ]);

            $source->loadMissing('fields');
            foreach ($source->fields as $field) {
                ChatCatalogProductTemplateField::query()->create([
                    'product_template_id' => $clone->id,
                    'field_key' => $field->field_key,
                    'label' => $field->label,
                    'field_type' => $field->field_type,
                    'level' => $field->level,
                    'required' => $field->required,
                    'searchable' => $field->searchable,
                    'filterable' => $field->filterable,
                    'customer_visible' => $field->customer_visible,
                    'options' => $field->options,
                    'validation' => $field->validation,
                    'visibility_rules' => $field->visibility_rules,
                    'sort_order' => $field->sort_order,
                ]);
            }

            return $clone->load('fields');
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function createWorkspaceTemplate(Workspace $workspace, array $payload): ChatCatalogProductTemplate
    {
        $name = $this->sanitizer->plainText((string) ($payload['name'] ?? ''), 255);
        if ($name === null || $name === '') {
            throw ValidationException::withMessages(['name' => 'Template name is required.']);
        }

        $slug = $this->uniqueWorkspaceSlug(
            $workspace,
            (string) ($payload['slug'] ?? '') !== '' ? (string) $payload['slug'] : Str::slug($name),
        );

        return DB::transaction(function () use ($workspace, $payload, $name, $slug): ChatCatalogProductTemplate {
            $template = ChatCatalogProductTemplate::query()->create([
                'workspace_id' => $workspace->id,
                'slug' => $slug,
                'name' => $name,
                'classification_hint' => $payload['classification_hint'] ?? null,
                'status' => ChatCatalogProductTemplateStatus::tryFrom((string) ($payload['status'] ?? 'active'))
                    ?? ChatCatalogProductTemplateStatus::Active,
                'schema_version' => max(1, (int) ($payload['schema_version'] ?? 1)),
                'is_system' => false,
                'source_template_id' => null,
            ]);

            foreach ($payload['fields'] ?? [] as $index => $fieldRow) {
                if (! is_array($fieldRow)) {
                    continue;
                }
                $this->validation->validateFieldDefinition($fieldRow);
                $this->createFieldFromPayload($template, $fieldRow, $index);
            }

            return $template->load('fields');
        });
    }

    /**
     * @param  array<string, mixed>  $fieldRow
     */
    private function createFieldFromPayload(
        ChatCatalogProductTemplate $template,
        array $fieldRow,
        int $index,
    ): ChatCatalogProductTemplateField {
        $fieldType = ChatCatalogProductTemplateFieldType::from((string) $fieldRow['field_type']);
        $level = ChatCatalogProductTemplateFieldLevel::from((string) ($fieldRow['level'] ?? 'product'));

        return ChatCatalogProductTemplateField::query()->create([
            'product_template_id' => $template->id,
            'field_key' => trim((string) $fieldRow['field_key']),
            'label' => is_array($fieldRow['label'] ?? null) ? $fieldRow['label'] : ['ar' => $fieldRow['field_key'], 'en' => $fieldRow['field_key']],
            'field_type' => $fieldType,
            'level' => $level,
            'required' => (bool) ($fieldRow['required'] ?? false),
            'searchable' => (bool) ($fieldRow['searchable'] ?? true),
            'filterable' => (bool) ($fieldRow['filterable'] ?? false),
            'customer_visible' => (bool) ($fieldRow['customer_visible'] ?? true),
            'options' => is_array($fieldRow['options'] ?? null) ? $fieldRow['options'] : null,
            'validation' => is_array($fieldRow['validation'] ?? null) ? $fieldRow['validation'] : null,
            'visibility_rules' => is_array($fieldRow['visibility_rules'] ?? null) ? $fieldRow['visibility_rules'] : null,
            'sort_order' => (int) ($fieldRow['sort_order'] ?? ($index * 10)),
        ]);
    }

    private function uniqueWorkspaceSlug(Workspace $workspace, string $desired): string
    {
        $base = Str::slug($desired) ?: 'template';
        $slug = $base;
        $suffix = 2;
        while (ChatCatalogProductTemplate::query()
            ->where('workspace_id', $workspace->id)
            ->where('slug', $slug)
            ->exists()) {
            $slug = $base.'-'.$suffix;
            $suffix++;
        }

        return $slug;
    }

    /**
     * Strip column-mapped keys from structured attributes before sync (avoid duplicate storage).
     *
     * @param  array<int, array<string, mixed>>  $structured
     * @return array<int, array<string, mixed>>
     */
    public function filterStructuredAttributesForSync(ChatCatalogProductTemplate $template, array $structured): array
    {
        $template->loadMissing('fields');
        $skipKeys = [];
        foreach ($template->fields as $field) {
            if ($field->mapsToItemColumn() !== null) {
                $skipKeys[$field->field_key] = true;
            }
        }

        return array_values(array_filter($structured, function ($row) use ($skipKeys): bool {
            if (! is_array($row)) {
                return false;
            }
            $key = trim((string) ($row['attribute_key'] ?? $row['key'] ?? ''));

            return $key !== '' && ! isset($skipKeys[$key]);
        }));
    }
}
