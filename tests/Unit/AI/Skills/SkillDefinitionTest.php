<?php

declare(strict_types=1);

namespace Tests\Unit\AI\Skills;

use App\AI\Skills\SkillDefinition;
use App\AI\Skills\SkillRegistryException;
use PHPUnit\Framework\TestCase;

class SkillDefinitionTest extends TestCase
{
    public function test_definition_normalizes_arrays_and_has_a_deterministic_fingerprint(): void
    {
        $first = new SkillDefinition(
            key: 'sales',
            name: 'Sales',
            description: 'Sell with facts only.',
            category: 'sales',
            supportedIntents: ['product_search', 'price_question', 'product_search'],
            supportedDomains: ['services', 'fashion', 'services'],
            allowedTools: ['search_products'],
            settingsValidation: [
                'properties' => [
                    'style' => ['description' => 'x', 'type' => 'string'],
                ],
                'type' => 'object',
                'allow_additional_properties' => true,
            ],
        );
        $second = new SkillDefinition(
            key: 'sales',
            name: 'Sales',
            description: 'Sell with facts only.',
            category: 'sales',
            supportedIntents: ['price_question', 'product_search'],
            supportedDomains: ['fashion', 'services'],
            allowedTools: ['search_products'],
            settingsValidation: [
                'allow_additional_properties' => true,
                'type' => 'object',
                'properties' => [
                    'style' => ['type' => 'string', 'description' => 'x'],
                ],
            ],
        );

        $this->assertSame(['price_question', 'product_search'], $first->supportedIntents);
        $this->assertSame(['fashion', 'services'], $first->supportedDomains);
        $this->assertSame($first->fingerprint(), $second->fingerprint());
        $this->assertSame('1:'.substr($first->fingerprint(), 0, 16), $first->versionFingerprint());
        $this->assertSame($first->toArray()['fingerprint'], $second->toArray()['fingerprint']);
    }

    public function test_invalid_definition_is_rejected(): void
    {
        $this->expectException(SkillRegistryException::class);

        new SkillDefinition(
            key: 'Sales Team',
            name: 'Sales',
            description: 'x',
            category: 'sales',
        );
    }
}
