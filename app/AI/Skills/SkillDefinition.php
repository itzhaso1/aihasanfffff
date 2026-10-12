<?php

declare(strict_types=1);

namespace App\AI\Skills;

use JsonException;

final class SkillDefinition
{
    private const KEY_PATTERN = '/^[a-z][a-z0-9_]*$/';

    /** @var list<string> */
    public readonly array $supportedIntents;

    /** @var list<string> */
    public readonly array $supportedDomains;

    /** @var list<string> */
    public readonly array $allowedTools;

    /** @var array<string, mixed> */
    public readonly array $settingsValidation;

    /**
     * @param  list<string>  $supportedIntents
     * @param  list<string>  $supportedDomains
     * @param  list<string>  $allowedTools
     * @param  array<string, mixed>  $settingsValidation
     */
    public function __construct(
        public readonly string $key,
        public readonly string $name,
        public readonly string $description,
        public readonly string $category,
        public readonly int $version = 1,
        public readonly bool $enabled = true,
        array $supportedIntents = [],
        array $supportedDomains = [],
        array $allowedTools = [],
        array $settingsValidation = [],
    ) {
        self::assertSelector('key', $key);
        self::assertText('name', $name);
        self::assertText('description', $description);
        self::assertText('category', $category);
        if ($version < 1) {
            throw SkillRegistryException::invalidDefinition('Skill "'.$key.'" must have version >= 1.');
        }

        $this->supportedIntents = self::normalizeSelectors('supported intent', $supportedIntents);
        $this->supportedDomains = self::normalizeSelectors('supported domain', $supportedDomains);
        $this->allowedTools = self::normalizeSelectors('allowed tool', $allowedTools);
        $this->settingsValidation = self::normalizeMetadata($settingsValidation);
    }

    public function fingerprint(): string
    {
        return hash('sha256', $this->jsonEncode($this->payload()));
    }

    public function versionFingerprint(): string
    {
        return $this->version.':'.substr($this->fingerprint(), 0, 16);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'name' => $this->name,
            'description' => $this->description,
            'category' => $this->category,
            'version' => $this->version,
            'enabled' => $this->enabled,
            'supported_intents' => $this->supportedIntents,
            'supported_domains' => $this->supportedDomains,
            'allowed_tools' => $this->allowedTools,
            'settings_validation' => $this->settingsValidation,
            'fingerprint' => $this->fingerprint(),
            'version_fingerprint' => $this->versionFingerprint(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(): array
    {
        return [
            'key' => $this->key,
            'name' => $this->name,
            'description' => $this->description,
            'category' => $this->category,
            'version' => $this->version,
            'enabled' => $this->enabled,
            'supported_intents' => $this->supportedIntents,
            'supported_domains' => $this->supportedDomains,
            'allowed_tools' => $this->allowedTools,
            'settings_validation' => $this->settingsValidation,
        ];
    }

    private static function assertSelector(string $field, string $value): void
    {
        if (preg_match(self::KEY_PATTERN, $value) !== 1) {
            throw SkillRegistryException::invalidDefinition('Skill '.$field.' must be a snake_case identifier.');
        }
    }

    private static function assertText(string $field, string $value): void
    {
        if (trim($value) === '') {
            throw SkillRegistryException::invalidDefinition('Skill '.$field.' cannot be empty.');
        }
    }

    /**
     * @param  list<string>  $values
     * @return list<string>
     */
    private static function normalizeSelectors(string $field, array $values): array
    {
        foreach ($values as $value) {
            if (! is_string($value)) {
                throw SkillRegistryException::invalidDefinition('Each '.$field.' must be a string.');
            }
            self::assertSelector($field, $value);
        }

        $values = array_values(array_unique($values));
        sort($values, SORT_STRING);

        return $values;
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    private static function normalizeMetadata(array $metadata): array
    {
        ksort($metadata, SORT_STRING);
        foreach ($metadata as $key => $value) {
            if (is_array($value)) {
                $metadata[$key] = array_is_list($value)
                    ? array_values(array_map(static fn (mixed $item): mixed => is_array($item) ? self::normalizeMetadata($item) : $item, $value))
                    : self::normalizeMetadata($value);
            }
        }

        return $metadata;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function jsonEncode(array $payload): string
    {
        try {
            return json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (JsonException $exception) {
            throw SkillRegistryException::invalidDefinition('Skill "'.$this->key.'" payload could not be encoded: '.$exception->getMessage());
        }
    }
}
