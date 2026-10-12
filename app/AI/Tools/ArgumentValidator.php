<?php

declare(strict_types=1);

namespace App\AI\Tools;

use InvalidArgumentException;

final class ArgumentValidator
{
    /**
     * @param  array<string, array{type?: string, required?: bool, description?: string}>  $inputSchema
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    public function validate(array $inputSchema, array $arguments): array
    {
        $validated = [];

        foreach ($inputSchema as $name => $rules) {
            if (! is_string($name) || $name === '') {
                continue;
            }

            $required = (bool) ($rules['required'] ?? false);
            $hasValue = array_key_exists($name, $arguments);

            if ($required && ! $hasValue) {
                throw new InvalidArgumentException("Missing required argument [{$name}].");
            }

            if (! $hasValue) {
                continue;
            }

            $validated[$name] = $this->assertType($name, $arguments[$name], (string) ($rules['type'] ?? 'string'));
        }

        foreach (array_keys($arguments) as $key) {
            if (! is_string($key)) {
                throw new InvalidArgumentException('Tool argument keys must be strings.');
            }
            if (! array_key_exists($key, $inputSchema)) {
                throw new InvalidArgumentException("Unknown argument [{$key}].");
            }
        }

        return $validated;
    }

    private function assertType(string $name, mixed $value, string $type): mixed
    {
        return match ($type) {
            'string' => $this->asString($name, $value),
            'integer', 'int' => $this->asInteger($name, $value),
            'number', 'float' => $this->asNumber($name, $value),
            'boolean', 'bool' => $this->asBoolean($name, $value),
            'array' => $this->asArray($name, $value),
            'object' => $this->asObject($name, $value),
            default => throw new InvalidArgumentException("Unsupported schema type [{$type}] for argument [{$name}]."),
        };
    }

    private function asString(string $name, mixed $value): string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            throw new InvalidArgumentException("Argument [{$name}] must be a string.");
        }

        return (string) $value;
    }

    private function asInteger(string $name, mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && ctype_digit($value)) {
            return (int) $value;
        }

        throw new InvalidArgumentException("Argument [{$name}] must be an integer.");
    }

    private function asNumber(string $name, mixed $value): float
    {
        if (! is_numeric($value)) {
            throw new InvalidArgumentException("Argument [{$name}] must be a number.");
        }

        return (float) $value;
    }

    private function asBoolean(string $name, mixed $value): bool
    {
        if (! is_bool($value)) {
            throw new InvalidArgumentException("Argument [{$name}] must be a boolean.");
        }

        return $value;
    }

    /**
     * @return array<mixed>
     */
    private function asArray(string $name, mixed $value): array
    {
        if (! is_array($value)) {
            throw new InvalidArgumentException("Argument [{$name}] must be an array.");
        }

        return $value;
    }

    /**
     * @return array<string, mixed>
     */
    private function asObject(string $name, mixed $value): array
    {
        if (! is_array($value)) {
            throw new InvalidArgumentException("Argument [{$name}] must be an object.");
        }

        return $value;
    }
}
