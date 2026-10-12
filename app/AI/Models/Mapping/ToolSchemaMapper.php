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
            $type = (string) ($rules['type'] ?? 'string');
            $properties[$name] = [
                'type' => $uppercaseTypes ? $this->geminiType($type) : $this->openaiType($type),
                'description' => (string) ($rules['description'] ?? ''),
            ];
            if ((bool) ($rules['required'] ?? false)) {
                $required[] = $name;
            }
        }

        return [$properties, $required];
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
