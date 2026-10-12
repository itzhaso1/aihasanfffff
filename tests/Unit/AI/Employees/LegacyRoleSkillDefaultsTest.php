<?php

declare(strict_types=1);

namespace Tests\Unit\AI\Employees;

use App\AI\Employees\Legacy\LegacyRoleSkillDefaults;
use App\AI\Skills\SkillRegistry;
use App\Support\Chat\ChatAiRole;
use PHPUnit\Framework\TestCase;

class LegacyRoleSkillDefaultsTest extends TestCase
{
    public function test_every_legacy_role_default_uses_canonical_registry_keys(): void
    {
        foreach (ChatAiRole::all() as $role) {
            $defaults = LegacyRoleSkillDefaults::for($role);
            $keys = array_keys($defaults);

            SkillRegistry::validateKeys($keys);
            $sorted = $keys;
            sort($sorted, SORT_STRING);

            $this->assertSame($sorted, $keys, $role.' defaults are deterministic');
            $this->assertSame(array_fill_keys($keys, []), $defaults);
        }
    }

    public function test_legacy_adapter_contains_no_skill_definition_metadata(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../../../app/AI/Employees/Legacy/LegacyRoleSkillDefaults.php');

        $this->assertStringNotContainsString('new SkillDefinition', $source);
        $this->assertStringNotContainsString('supportedIntents', $source);
        $this->assertStringNotContainsString('allowedTools', $source);
    }
}
