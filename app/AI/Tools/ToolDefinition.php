<?php

declare(strict_types=1);

namespace App\AI\Tools;

final class ToolDefinition
{
    private const KEY_PATTERN = '/^[a-z][a-z0-9_]*$/';

    /** @var list<string> */
    public readonly array $allowedSkillKeys;

    /**
     * @param  array<string, array{type: string, required?: bool, description?: string}>  $inputSchema
     * @param  list<string>  $allowedSkillKeys
     */
    public function __construct(
        public readonly string $name,
        public readonly string $description,
        public readonly string $category,
        public readonly int $version = 1,
        public readonly bool $enabled = true,
        public readonly ToolAccess $access = ToolAccess::Read,
        public readonly ToolRisk $risk = ToolRisk::Read,
        public readonly bool $writes = false,
        public readonly bool $requiresConfirmation = false,
        public readonly int $maxCallsPerRun = 3,
        array $allowedSkillKeys = [],
        public readonly array $inputSchema = [],
    ) {
        if (preg_match(self::KEY_PATTERN, $name) !== 1) {
            throw ToolRegistryException::invalid('Tool name must be a snake_case identifier.');
        }
        if (trim($description) === '' || trim($category) === '') {
            throw ToolRegistryException::invalid('Tool description and category cannot be empty.');
        }
        if ($version < 1 || $maxCallsPerRun < 1) {
            throw ToolRegistryException::invalid('Tool version and max calls must be at least 1.');
        }
        if ($writes && $access !== ToolAccess::Write) {
            throw ToolRegistryException::invalid('Write tools must use write access.');
        }

        $skills = array_values(array_unique($allowedSkillKeys));
        sort($skills, SORT_STRING);
        $this->allowedSkillKeys = $skills;
    }

    public function fingerprint(): string
    {
        return hash('sha256', json_encode($this->payload(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            ...$this->payload(),
            'fingerprint' => $this->fingerprint(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(): array
    {
        return [
            'name' => $this->name,
            'description' => $this->description,
            'category' => $this->category,
            'version' => $this->version,
            'enabled' => $this->enabled,
            'access' => $this->access->value,
            'risk' => $this->risk->value,
            'writes' => $this->writes,
            'requires_confirmation' => $this->requiresConfirmation,
            'max_calls_per_run' => $this->maxCallsPerRun,
            'allowed_skill_keys' => $this->allowedSkillKeys,
            'input_schema' => $this->inputSchema,
        ];
    }
}
