<?php

namespace App\Models\Commerce;

use App\Enums\Commerce\CommerceProvenanceSource;
use App\Models\Concerns\BelongsToWorkspace;
use App\Models\WorkspaceScopedModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'workspace_id',
    'commerce_attribute_definition_id',
    'entity_type',
    'entity_id',
    'value_string',
    'value_number',
    'value_boolean',
    'value_json',
    'unit',
    'source',
    'confidence',
])]
class CommerceEntityAttributeValue extends WorkspaceScopedModel
{
    use BelongsToWorkspace;

    protected $table = 'commerce_entity_attribute_values';

    protected function casts(): array
    {
        return [
            'entity_id' => 'integer',
            'value_number' => 'decimal:4',
            'value_boolean' => 'boolean',
            'value_json' => 'array',
            'source' => CommerceProvenanceSource::class,
            'confidence' => 'float',
        ];
    }

    public function definition(): BelongsTo
    {
        return $this->belongsTo(CommerceAttributeDefinition::class, 'commerce_attribute_definition_id');
    }

    public function rawTypedValue(): mixed
    {
        $type = $this->definition?->data_type?->value ?? 'string';

        return match ($type) {
            'integer', 'decimal', 'measurement', 'dimension' => $this->value_number !== null ? (float) $this->value_number : null,
            'boolean' => $this->value_boolean,
            'json', 'multi_value', 'range', 'reference' => $this->value_json ?? ($this->value_string ? json_decode($this->value_string, true) : null),
            default => $this->value_string,
        };
    }
}
