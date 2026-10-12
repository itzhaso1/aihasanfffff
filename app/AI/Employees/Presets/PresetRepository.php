<?php

declare(strict_types=1);

namespace App\AI\Employees\Presets;

use App\AI\Employees\Drafts\EmployeeDraftMapper;
use App\AI\Employees\EmployeeOperationException;
use App\AI\Employees\InvalidEmployeeData;
use JsonException;

/**
 * Reads versioned preset JSON files from this directory. Every preset is validated by
 * building a full employee draft from it, so an invalid preset can never be offered.
 */
final class PresetRepository
{
    private const KEY_PATTERN = '/^[a-z][a-z0-9_]*$/';

    private const MAX_FILE_BYTES = 16_384;

    /** Preset fields that describe the template itself rather than the employee. */
    private const TEMPLATE_FIELDS = ['key', 'version', 'display_name', 'summary'];

    private readonly string $directory;

    /** @var ?array<string, EmployeePreset> */
    private ?array $presets = null;

    public function __construct(
        private readonly EmployeeDraftMapper $mapper = new EmployeeDraftMapper,
        ?string $directory = null,
    ) {
        $this->directory = $directory ?? __DIR__;
    }

    /**
     * @return list<EmployeePreset> Ordered by key.
     */
    public function all(): array
    {
        return array_values($this->load());
    }

    public function find(string $key): ?EmployeePreset
    {
        return $this->load()[$key] ?? null;
    }

    public function get(string $key): EmployeePreset
    {
        return $this->find($key) ?? throw EmployeeOperationException::unknownPreset($key);
    }

    /**
     * @return array<string, EmployeePreset>
     */
    private function load(): array
    {
        if ($this->presets !== null) {
            return $this->presets;
        }

        $presets = [];
        $files = glob($this->directory.DIRECTORY_SEPARATOR.'*.json') ?: [];
        sort($files, SORT_STRING);
        foreach ($files as $file) {
            $preset = $this->parse($file);
            $presets[$preset->key] = $preset;
        }
        ksort($presets, SORT_STRING);

        return $this->presets = $presets;
    }

    private function parse(string $file): EmployeePreset
    {
        $name = basename($file, '.json');
        if (filesize($file) > self::MAX_FILE_BYTES) {
            throw new InvalidPresetException($name, 'file is too large');
        }
        try {
            $data = json_decode((string) file_get_contents($file), true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new InvalidPresetException($name, 'file is not valid JSON');
        }
        if (! is_array($data)) {
            throw new InvalidPresetException($name, 'root must be an object');
        }
        $key = $data['key'] ?? null;
        if (! is_string($key) || preg_match(self::KEY_PATTERN, $key) !== 1 || $key !== $name) {
            throw new InvalidPresetException($name, 'key must match the file name');
        }
        if (! is_int($data['version'] ?? null) || $data['version'] < 1) {
            throw new InvalidPresetException($name, 'version must be a positive integer');
        }
        if (! is_string($data['display_name'] ?? null)) {
            throw new InvalidPresetException($name, 'display_name is required');
        }

        $employee = array_diff_key($data, array_flip(self::TEMPLATE_FIELDS));
        $employee['name'] = $data['display_name'];
        try {
            $this->mapper->fromArray($employee);
        } catch (InvalidEmployeeData $exception) {
            throw new InvalidPresetException($name, $exception->field.': '.$exception->getMessage());
        }

        return new EmployeePreset(
            key: $key,
            version: $data['version'],
            displayName: $data['display_name'],
            avatarKey: is_string($data['avatar_key'] ?? null) ? $data['avatar_key'] : null,
            summary: is_string($data['summary'] ?? null) ? $data['summary'] : null,
            employee: $employee,
        );
    }
}
