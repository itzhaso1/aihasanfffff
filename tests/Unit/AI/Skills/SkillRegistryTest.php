<?php

declare(strict_types=1);

namespace Tests\Unit\AI\Skills;

use App\AI\Skills\SkillDefinition;
use App\AI\Skills\SkillRegistry;
use App\AI\Skills\SkillRegistryException;
use PHPUnit\Framework\TestCase;

class SkillRegistryTest extends TestCase
{
    public function test_valid_lookup_and_ordering_are_deterministic(): void
    {
        $keys = SkillRegistry::keys();

        $this->assertSame(
            ['complaint_handling', 'customer_support', 'faq', 'human_handoff', 'order_handling', 'product_recommendation', 'product_search', 'sales'],
            $keys,
        );
        $this->assertSame($keys, SkillRegistry::enabledKeys());
        $this->assertSame('sales', SkillRegistry::find('sales')?->key);
        $this->assertNull(SkillRegistry::find('does_not_exist'));
    }

    public function test_unknown_skill_rejection_and_duplicate_key_detection(): void
    {
        try {
            SkillRegistry::validateKeys(['sales', 'flying']);
            $this->fail('Unknown skill was accepted.');
        } catch (SkillRegistryException $exception) {
            $this->assertSame('Unknown skill "flying".', $exception->getMessage());
        }

        $this->expectException(SkillRegistryException::class);
        new SkillRegistry([
            new SkillDefinition('sales', 'Sales', 'x', 'sales'),
            new SkillDefinition('sales', 'Sales Copy', 'y', 'sales'),
        ]);
    }

    public function test_registry_fingerprint_is_deterministic_and_content_sensitive(): void
    {
        $a = new SkillRegistry([
            new SkillDefinition('sales', 'Sales', 'x', 'sales', supportedIntents: ['price_question']),
            new SkillDefinition('faq', 'FAQ', 'y', 'knowledge'),
        ]);
        $b = new SkillRegistry([
            new SkillDefinition('faq', 'FAQ', 'y', 'knowledge'),
            new SkillDefinition('sales', 'Sales', 'x', 'sales', supportedIntents: ['price_question']),
        ]);
        $changed = new SkillRegistry([
            new SkillDefinition('faq', 'FAQ', 'y', 'knowledge'),
            new SkillDefinition('sales', 'Sales', 'changed', 'sales', supportedIntents: ['price_question']),
        ]);

        $this->assertSame($a->registryFingerprint(), $b->registryFingerprint());
        $this->assertNotSame($a->registryFingerprint(), $changed->registryFingerprint());
    }

    public function test_disabled_skills_are_excluded_and_rejected_for_validation(): void
    {
        $registry = new SkillRegistry([
            new SkillDefinition('archived_skill', 'Archived', 'x', 'legacy', enabled: false),
            new SkillDefinition('sales', 'Sales', 'y', 'sales'),
        ]);

        $this->assertSame(['sales'], $registry->skillKeys(false));
        $this->assertNull($registry->definition('archived_skill', false));
        $this->assertSame('archived_skill', $registry->definition('archived_skill')?->key);

        $this->expectException(SkillRegistryException::class);
        $registry->validateSkillKeys(['archived_skill']);
    }
}
