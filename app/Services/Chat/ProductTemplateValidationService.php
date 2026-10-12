<?php

namespace App\Services\Chat;

use App\Enums\ChatCatalog\ChatCatalogProductTemplateFieldLevel;
use App\Enums\ChatCatalog\ChatCatalogProductTemplateFieldType;
use App\Models\ChatCatalogItem;
use App\Models\ChatCatalogProductTemplate;
use App\Models\ChatCatalogProductTemplateField;
use App\Support\Money\Money;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final class ProductTemplateValidationService
{
    public function __construct(
        private readonly ChatCatalogDataSanitizer $sanitizer,
    ) {}

    /**
     * @param  array<string, mixed>  $fieldRow
     */
    public function validateFieldDefinition(array $fieldRow): void
    {
        $fieldKey = trim((string) ($fieldRow['field_key'] ?? ''));
        if ($fieldKey === '' || ! preg_match('/^[a-z][a-z0-9_]{0,63}$/', $fieldKey)) {
            throw ValidationException::withMessages([
                'field_key' => 'Field key must be lowercase snake_case.',
            ]);
        }

        $type = ChatCatalogProductTemplateFieldType::tryFrom((string) ($fieldRow['field_type'] ?? ''));
        if ($type === null) {
            throw ValidationException::withMessages([
                'field_type' => 'Unsupported field type.',
            ]);
        }

        $level = ChatCatalogProductTemplateFieldLevel::tryFrom((string) ($fieldRow['level'] ?? 'product'));
        if ($level === null) {
            throw ValidationException::withMessages([
                'level' => 'Field level must be product or variant.',
            ]);
        }

        if ($type === ChatCatalogProductTemplateFieldType::Select) {
            $choices = data_get($fieldRow, 'options.choices');
            if (! is_array($choices)) {
                throw ValidationException::withMessages([
                    'options' => 'Select fields require options.choices.',
                ]);
            }
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $structuredAttributes
     * @param  array<int, array<string, mixed>>  $variants
     */
    public function validateItemPayload(
        ChatCatalogProductTemplate $template,
        array $structuredAttributes,
        array $variants,
        array $itemScalars = [],
    ): void {
        $template->loadMissing('fields');
        $fields = $template->fields;

        $productValues = $this->indexedAttributeValues($structuredAttributes, null);
        $this->validateLevelFields($fields, ChatCatalogProductTemplateFieldLevel::Product, $productValues, $itemScalars);

        foreach ($variants as $index => $variantRow) {
            if (! is_array($variantRow)) {
                continue;
            }
            $variantAttrs = is_array($variantRow['structured_attributes'] ?? null)
                ? $variantRow['structured_attributes']
                : [];
            $variantValues = $this->indexedAttributeValues($variantAttrs, null);
            $this->validateLevelFields(
                $fields,
                ChatCatalogProductTemplateFieldLevel::Variant,
                $variantValues,
                [],
                "variants.{$index}.structured_attributes",
            );
        }
    }

    /**
     * @param  Collection<int, ChatCatalogProductTemplateField>  $fields
     * @param  array<string, mixed>  $values
     * @param  array<string, mixed>  $itemScalars
     */
    private function validateLevelFields(
        Collection $fields,
        ChatCatalogProductTemplateFieldLevel $level,
        array $values,
        array $itemScalars,
        string $errorPrefix = 'structured_attributes',
    ): void {
        foreach ($fields as $field) {
            if ($field->level !== $level) {
                continue;
            }

            $value = $this->resolveFieldValue($field, $values, $itemScalars);

            if ($field->required && ($value === null || $value === '')) {
                throw ValidationException::withMessages([
                    "{$errorPrefix}.{$field->field_key}" => 'This field is required.',
                ]);
            }

            if ($value === null || $value === '') {
                continue;
            }

            $this->validateTypedValue($field, $value, "{$errorPrefix}.{$field->field_key}");
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function indexedAttributeValues(array $rows, ?int $variantId): array
    {
        $values = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $key = trim((string) ($row['attribute_key'] ?? $row['key'] ?? ''));
            if ($key === '') {
                continue;
            }
            $values[$key] = $row['attribute_value'] ?? $row['value'] ?? null;
        }

        return $values;
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  array<string, mixed>  $itemScalars
     */
    private function resolveFieldValue(
        ChatCatalogProductTemplateField $field,
        array $values,
        array $itemScalars,
    ): mixed {
        if ($field->mapsToItemColumn() === 'price' && array_key_exists('price', $itemScalars)) {
            return $itemScalars['price'];
        }
        if ($field->mapsToItemColumn() === 'brand' && array_key_exists('brand', $itemScalars)) {
            return $itemScalars['brand'];
        }

        return $values[$field->field_key] ?? null;
    }

    private function validateTypedValue(
        ChatCatalogProductTemplateField $field,
        mixed $value,
        string $errorKey,
    ): void {
        $stringValue = is_scalar($value) ? trim((string) $value) : '';

        match ($field->field_type) {
            ChatCatalogProductTemplateFieldType::Text,
            ChatCatalogProductTemplateFieldType::Textarea => $this->assertMaxLength($stringValue, 2000, $errorKey),

            ChatCatalogProductTemplateFieldType::Number => $this->assertNumeric($stringValue, $errorKey),

            ChatCatalogProductTemplateFieldType::Boolean => $this->assertBoolean($value, $errorKey),

            ChatCatalogProductTemplateFieldType::Select => $this->assertSelect($field, $stringValue, $errorKey),

            ChatCatalogProductTemplateFieldType::Measurement => $this->assertMeasurement($stringValue, $errorKey),

            ChatCatalogProductTemplateFieldType::Money => $this->assertMoney($stringValue, $errorKey),
        };
    }

    private function assertMaxLength(string $value, int $max, string $errorKey): void
    {
        if (mb_strlen($value) > $max) {
            throw ValidationException::withMessages([$errorKey => 'Value is too long.']);
        }
    }

    private function assertNumeric(string $value, string $errorKey): void
    {
        if ($value !== '' && ! is_numeric($value)) {
            throw ValidationException::withMessages([$errorKey => 'Value must be numeric.']);
        }
    }

    private function assertBoolean(mixed $value, string $errorKey): void
    {
        if (! in_array($value, [true, false, 0, 1, '0', '1', 'true', 'false'], true)) {
            throw ValidationException::withMessages([$errorKey => 'Value must be boolean.']);
        }
    }

    private function assertSelect(ChatCatalogProductTemplateField $field, string $value, string $errorKey): void
    {
        $choices = data_get($field->options, 'choices', []);
        if (! is_array($choices) || $choices === []) {
            return;
        }
        $normalized = array_map(fn ($c) => (string) $c, $choices);
        if (! in_array($value, $normalized, true)) {
            throw ValidationException::withMessages([$errorKey => 'Invalid option selected.']);
        }
    }

    private function assertMeasurement(string $value, string $errorKey): void
    {
        if ($value === '') {
            return;
        }
        if (! preg_match('/^\d+(\.\d+)?(\s*[a-zA-Z%]+)?$/u', $value)) {
            throw ValidationException::withMessages([$errorKey => 'Invalid measurement format.']);
        }
    }

    private function assertMoney(string $value, string $errorKey): void
    {
        if ($value === '') {
            return;
        }
        try {
            Money::of($value);
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages([$errorKey => 'Invalid money amount.']);
        }
    }

    /**
     * @return array<string, ChatCatalogProductTemplateField>
     */
    public function fieldMapForItem(ChatCatalogItem $item): array
    {
        if ($item->product_template_id === null) {
            return [];
        }

        $template = $item->relationLoaded('productTemplate')
            ? $item->productTemplate
            : ChatCatalogProductTemplate::query()->with('fields')->find($item->product_template_id);

        if (! $template) {
            return [];
        }

        $map = [];
        foreach ($template->fields as $field) {
            $map[$field->field_key] = $field;
        }

        return $map;
    }

    public function isAttributeSearchable(ChatCatalogItem $item, string $attributeKey): bool
    {
        $field = $this->fieldMapForItem($item)[$attributeKey] ?? null;

        return $field === null ? true : $field->searchable;
    }

    public function isAttributeCustomerVisible(ChatCatalogItem $item, string $attributeKey): bool
    {
        $field = $this->fieldMapForItem($item)[$attributeKey] ?? null;

        return $field === null ? true : $field->customer_visible;
    }
}
