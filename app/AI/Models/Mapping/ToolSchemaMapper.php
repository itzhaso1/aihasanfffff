<?php

declare(strict_types=1);

namespace App\AI\Models\Mapping;

use App\AI\Core\Data\ToolSpec;

final class ToolSchemaMapper
{
    /**
     * @param  list<ToolSpec>  $tools
     * @return list<array<string, mixed>>
     */
    public function geminiDeclarations(array $tools): array
    {
        $declarations = [];
        foreach ($tools as $tool) {
            [$properties, $required] = $this->properties($tool, true);
            $declarations[] = [
                'name' => $tool->name,
                'description' => $tool->description,
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => $properties,
                    'required' => $required,
                ],
            ];
        }

        return $declarations;
    }

    /**
     * @param  list<ToolSpec>  $tools
     * @return list<array<string, mixed>>
     */
    public function openaiTools(array $tools): array
    {
        $mapped = [];
        foreach ($tools as $tool) {
            [$properties, $required] = $this->properties($tool, false);
            $mapped[] = [
                'type' => 'function',
                'function' => [
                    'name' => $tool->name,
                    'description' => $tool->description,
                    'parameters' => [
                        'type' => 'object',
                        'properties' => $properties,
                        'required' => $required,
                    ],
                ],
            ];
        }

        return $mapped;
    }

    /**
     * @return array{0: array<string, array<string, mixed>>, 1: list<string>}
     */
    private function properties(ToolSpec $tool, bool $uppercaseTypes): array
    {
        $properties = [];
        $required = [];
        foreach ($tool->inputSchema as $name => $rules) {
            if (! is_string($name) || $name === '') {
                continue;
            }
            $rules = is_array($rules) ? $rules : [];
            $properties[$name] = $this->mapSchemaNode($rules, $uppercaseTypes);
            if ((bool) ($rules['required'] ?? false)) {
                $required[] = $name;
            }
        }

        return [$properties === [] ? (object) [] : $properties, $required];
    }

    /**
     * @param  array<string, mixed>  $rules
     * @return array<string, mixed>
     */
    private function mapSchemaNode(array $rules, bool $uppercaseTypes): array
    {
        $type = strtolower((string) ($rules['type'] ?? 'string'));
        $node = [
            'type' => $uppercaseTypes ? $this->geminiType($type) : $this->openaiType($type),
        ];

        $description = (string) ($rules['description'] ?? '');
        if ($description !== '' || ! isset($rules['items'])) {
            $node['description'] = $description;
        }

        if ($type === 'array') {
            $items = is_array($rules['items'] ?? null) ? $rules['items'] : ['type' => 'object'];
            $node['items'] = $this->mapSchemaNode($items, $uppercaseTypes);
        } elseif ($type === 'object' && is_array($rules['properties'] ?? null)) {
            $nestedProps = [];
            $nestedRequired = [];
            foreach ($rules['properties'] as $propName => $propRules) {
                if (! is_string($propName) || $propName === '') {
                    continue;
                }
                $propRules = is_array($propRules) ? $propRules : [];
                $nestedProps[$propName] = $this->mapSchemaNode($propRules, $uppercaseTypes);
                if ((bool) ($propRules['required'] ?? false)) {
                    $nestedRequired[] = $propName;
                }
            }
            $node['properties'] = $nestedProps === [] ? (object) [] : $nestedProps;
            if ($nestedRequired !== []) {
                $node['required'] = $nestedRequired;
            }
        }

        return $node;
    }

    private function geminiType(string $type): string
    {
        return match (strtolower($type)) {
            'integer', 'int' => 'INTEGER',
            'number', 'float' => 'NUMBER',
            'boolean', 'bool' => 'BOOLEAN',
            'array' => 'ARRAY',
            'object' => 'OBJECT',
            default => 'STRING',
        };
    }

    private function openaiType(string $type): string
    {
        return match (strtolower($type)) {
            'integer', 'int' => 'integer',
            'number', 'float' => 'number',
            'boolean', 'bool' => 'boolean',
            'array' => 'array',
            'object' => 'object',
            default => 'string',
        };
    }
}
