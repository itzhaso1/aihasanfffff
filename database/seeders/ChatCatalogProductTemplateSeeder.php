<?php

namespace Database\Seeders;

use App\Enums\ChatCatalog\ChatCatalogProductTemplateFieldLevel;
use App\Enums\ChatCatalog\ChatCatalogProductTemplateFieldType;
use App\Enums\ChatCatalog\ChatCatalogProductTemplateStatus;
use App\Models\ChatCatalogProductTemplate;
use App\Models\ChatCatalogProductTemplateField;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ChatCatalogProductTemplateSeeder extends Seeder
{
    public function run(): void
    {
        $definitions = config('chat_catalog_product_templates', []);

        foreach ($definitions as $definition) {
            DB::transaction(function () use ($definition): void {
                $slug = (string) ($definition['slug'] ?? '');
                if ($slug === '') {
                    return;
                }

                $template = ChatCatalogProductTemplate::query()->updateOrCreate(
                    [
                        'slug' => $slug,
                        'is_system' => true,
                        'workspace_id' => null,
                    ],
                    [
                        'name' => (string) ($definition['name'] ?? $slug),
                        'classification_hint' => $definition['classification_hint'] ?? null,
                        'status' => ChatCatalogProductTemplateStatus::Active,
                        'schema_version' => (int) ($definition['schema_version'] ?? 1),
                        'source_template_id' => null,
                    ],
                );

                $incomingKeys = [];
                foreach ($definition['fields'] ?? [] as $index => $fieldRow) {
                    if (! is_array($fieldRow)) {
                        continue;
                    }
                    $fieldKey = trim((string) ($fieldRow['field_key'] ?? ''));
                    if ($fieldKey === '') {
                        continue;
                    }
                    $incomingKeys[] = $fieldKey;

                    $fieldType = ChatCatalogProductTemplateFieldType::tryFrom((string) ($fieldRow['field_type'] ?? 'text'))
                        ?? ChatCatalogProductTemplateFieldType::Text;
                    $level = ChatCatalogProductTemplateFieldLevel::tryFrom((string) ($fieldRow['level'] ?? 'product'))
                        ?? ChatCatalogProductTemplateFieldLevel::Product;

                    ChatCatalogProductTemplateField::query()->updateOrCreate(
                        [
                            'product_template_id' => $template->id,
                            'field_key' => $fieldKey,
                        ],
                        [
                            'label' => is_array($fieldRow['label'] ?? null) ? $fieldRow['label'] : ['ar' => $fieldKey, 'en' => $fieldKey],
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
                        ],
                    );
                }

                $template->fields()
                    ->whereNotIn('field_key', $incomingKeys)
                    ->delete();
            });
        }
    }
}
