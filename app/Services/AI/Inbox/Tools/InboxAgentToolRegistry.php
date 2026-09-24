<?php

namespace App\Services\AI\Inbox\Tools;

use App\Services\AI\Inbox\Tools\Contracts\InboxAgentTool;

/**
 * Explicit allowlist of Inbox agent tools. Unknown names are never executed.
 */
final class InboxAgentToolRegistry
{
    /** @var array<string, InboxAgentTool> */
    private array $tools = [];

    public function register(InboxAgentTool $tool): void
    {
        $name = $tool->name();
        if ($name === '') {
            throw new InboxAgentToolRegistryException('Tool name cannot be empty.');
        }

        if (isset($this->tools[$name])) {
            throw InboxAgentToolRegistryException::duplicate($name);
        }

        $this->tools[$name] = $tool;
    }

    public function has(string $name): bool
    {
        return isset($this->tools[$name]);
    }

    public function get(string $name): InboxAgentTool
    {
        if (! isset($this->tools[$name])) {
            throw InboxAgentToolRegistryException::unknown($name);
        }

        return $this->tools[$name];
    }

    public function count(): int
    {
        return count($this->tools);
    }

    /**
     * @return list<InboxAgentTool>
     */
    public function all(): array
    {
        $tools = array_values($this->tools);
        usort($tools, static fn (InboxAgentTool $a, InboxAgentTool $b): int => strcmp($a->name(), $b->name()));

        return $tools;
    }

    /**
     * Deterministic provider-neutral definitions (sorted by tool name).
     *
     * @return list<InboxAgentToolDefinition>
     */
    public function definitions(): array
    {
        return array_map(
            static fn (InboxAgentTool $tool): InboxAgentToolDefinition => InboxAgentToolDefinition::fromTool($tool),
            $this->all(),
        );
    }
}
