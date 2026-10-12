<?php

declare(strict_types=1);

namespace Tests\Unit\AI\Training;

use App\AI\Skills\SkillRegistry;
use App\AI\Training\TrainingDimension;
use App\AI\Training\TrainingVocabulary;
use PHPUnit\Framework\TestCase;

class TrainingVocabularyCompatibilityTest extends TestCase
{
    public function test_skill_values_delegate_to_the_canonical_registry(): void
    {
        $this->assertSame(
            SkillRegistry::enabledKeys(),
            TrainingVocabulary::values(TrainingDimension::Skills),
        );
    }

    public function test_skill_lookup_uses_registry_keys_while_other_dimensions_stay_intact(): void
    {
        $this->assertTrue(TrainingVocabulary::knows(TrainingDimension::Skills, 'sales'));
        $this->assertFalse(TrainingVocabulary::knows(TrainingDimension::Skills, 'flying'));
        $this->assertContains('price_question', TrainingVocabulary::values(TrainingDimension::Intents));
        $this->assertContains('sales_agent', TrainingVocabulary::values(TrainingDimension::Roles));
    }
}
