<?php

namespace App\Services\AI\Providers\GoogleAiStudio;

use App\Services\AI\Inbox\Tools\InboxAgentToolDefinition;

/**
 * Maps provider-neutral Inbox tool definitions to Gemini function declarations.
 */
final class GoogleAiStudioToolSchemaMapper
{
    /**
     * @param  list<InboxAgentToolDefinition>  $definitions
     * @return list<array<string, mixed>>
     */
    public function toFunctionDeclarations(array $definitions): array
    {
        $declarations = [];

        foreach ($definitions as $definition) {
            $properties = [];
            $required = [];

            foreach ($definition->inputSchema as $name => $rules) {
                if (! is_string($name) || $name === '') {
                    continue;
                }

                $rules = is_array($rules) ? $rules : [];
                $type = strtolower((string) ($rules['type'] ?? 'string'));
                $property = [
                    'type' => $this->mapType($type),
                    'description' => (string) ($rules['description'] ?? ''),
                ];

                if ($type === 'array') {
                    $items = is_array($rules['items'] ?? null) ? $rules['items'] : ['type' => 'object'];
                    $property['items'] = [
                        'type' => $this->mapType((string) ($items['type'] ?? 'object')),
                    ];
                }

                $properties[$name] = $property;

                if ((bool) ($rules['required'] ?? false)) {
                    $required[] = $name;
                }
            }

            $declarations[] = [
                'name' => $definition->name,
                'description' => $definition->description,
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => $properties === [] ? (object) [] : $properties,
                    'required' => $required,
                ],
            ];
        }

        return $declarations;
    }

    private function mapType(string $type): string
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
}
