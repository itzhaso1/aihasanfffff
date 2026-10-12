<?php

declare(strict_types=1);

namespace App\AI\Tools;

use App\AI\Core\Data\ToolSpec;
use App\AI\Tools\Contracts\RuntimeTool;

final class ToolRegistry
{
    /** @var array<string, RuntimeTool> */
    private array $tools = [];

    /**
     * @param  iterable<RuntimeTool>  $tools
     */
    public function __construct(iterable $tools = [])
    {
        foreach ($tools as $tool) {
            if (! $tool instanceof RuntimeTool) {
                throw ToolRegistryException::invalid('Every registered tool must implement RuntimeTool.');
            }
            $this->register($tool);
        }
    }

    public function register(RuntimeTool $tool): void
    {
        $name = $tool->definition()->name;
        if (isset($this->tools[$name])) {
            throw ToolRegistryException::duplicate($name);
        }

        $this->tools[$name] = $tool;
    }

    public function has(string $name): bool
    {
        return isset($this->tools[$name]);
    }

    public function get(string $name): RuntimeTool
    {
        return $this->tools[$name] ?? throw ToolRegistryException::unknown($name);
    }

    public function definition(string $name): ToolDefinition
    {
        return $this->get($name)->definition();
    }

    /**
     * @return list<RuntimeTool>
     */
    public function all(bool $includeDisabled = true): array
    {
        $tools = array_values($this->tools);
        usort($tools, static fn (RuntimeTool $a, RuntimeTool $b): int => strcmp($a->definition()->name, $b->definition()->name));

        if ($includeDisabled) {
            return $tools;
        }

        return array_values(array_filter(
            $tools,
            static fn (RuntimeTool $tool): bool => $tool->definition()->enabled,
        ));
    }

    /**
     * @return list<ToolDefinition>
     */
    public function definitions(bool $includeDisabled = true): array
    {
        return array_map(
            static fn (RuntimeTool $tool): ToolDefinition => $tool->definition(),
            $this->all($includeDisabled),
        );
    }

    /**
     * @return list<ToolSpec>
     */
    public function specsFor(array $names): array
    {
        $specs = [];
        foreach ($this->all(false) as $tool) {
            $definition = $tool->definition();
            if (! in_array($definition->name, $names, true)) {
                continue;
            }
            $specs[] = new ToolSpec($definition->name, $definition->description, $definition->inputSchema);
        }

        return $specs;
    }

    public function fingerprint(): string
    {
        $payload = [];
        foreach ($this->definitions() as $definition) {
            $payload[$definition->name] = $definition->fingerprint();
        }

        return hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }
}
