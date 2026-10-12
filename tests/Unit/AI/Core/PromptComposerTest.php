<?php

declare(strict_types=1);

namespace Tests\Unit\AI\Core;

use App\AI\Core\Prompt\PromptCompositionException;
use App\AI\Core\Prompt\PromptLayer;
use App\AI\Core\Prompt\PromptSection;
use App\AI\Core\PromptComposer;
use PHPUnit\Framework\TestCase;

class PromptComposerTest extends TestCase
{
    public function test_layers_are_composed_in_exact_precedence_order(): void
    {
        $composition = (new PromptComposer)->compose(
            constitution: [$this->section(PromptLayer::Constitution, 'constitution.core')],
            policy: [$this->section(PromptLayer::Policy, 'policy.security')],
            employee: [$this->section(PromptLayer::Employee, 'employee.identity')],
            training: [$this->section(PromptLayer::Training, 'training.style')],
            context: [$this->section(PromptLayer::Context, 'context.history')],
            userMessage: PromptSection::userMessage('كم'),
        );

        $this->assertSame(
            ['constitution', 'policy', 'employee', 'training', 'context', 'user_message'],
            array_column($composition->toArray(), 'layer'),
        );
        $this->assertSame(
            ['constitution.core', 'policy.security', 'employee.identity', 'training.style', 'context.history', 'user.message'],
            $composition->sectionKeys(),
        );
        $this->assertSame('كم', $composition->userMessage()?->content);
    }

    public function test_composition_is_deterministic_and_keeps_order_inside_a_layer(): void
    {
        $build = fn () => (new PromptComposer)->compose(
            constitution: [
                $this->section(PromptLayer::Constitution, 'constitution.truth'),
                $this->section(PromptLayer::Constitution, 'constitution.core'),
            ],
            policy: [],
            employee: [],
            training: [],
            context: [],
            userMessage: PromptSection::userMessage('مرحبا'),
        );

        $first = $build();
        $second = $build();

        $this->assertSame($first->toArray(), $second->toArray());
        $this->assertSame($first->fingerprint(), $second->fingerprint());
        $this->assertSame(['constitution.truth', 'constitution.core', 'user.message'], $first->sectionKeys());
    }

    public function test_output_contains_only_provided_content(): void
    {
        $sections = [
            $this->section(PromptLayer::Constitution, 'constitution.core'),
            $this->section(PromptLayer::Employee, 'employee.identity'),
        ];
        $composition = (new PromptComposer)->compose(
            constitution: [$sections[0]],
            policy: [],
            employee: [$sections[1]],
            training: [],
            context: [],
            userMessage: PromptSection::userMessage('USER'),
        );

        $this->assertSame(
            ['CONTENT:constitution.core', 'CONTENT:employee.identity', 'USER'],
            array_column($composition->toArray(), 'content'),
        );
        foreach ($composition->toArray() as $row) {
            $this->assertSame(['layer', 'key', 'content', 'source', 'version'], array_keys($row));
        }
    }

    public function test_empty_layers_and_blank_sections_are_dropped(): void
    {
        $composition = (new PromptComposer)->compose(
            constitution: [],
            policy: [new PromptSection(PromptLayer::Policy, 'policy.blank', "  \n ")],
            employee: [],
            training: [],
            context: [],
            userMessage: PromptSection::userMessage('مرحبا'),
        );

        $this->assertSame(['user.message'], $composition->sectionKeys());
        $this->assertSame([], $composition->forLayer(PromptLayer::Training));
        $this->assertSame([], $composition->forLayer(PromptLayer::Policy));
    }

    public function test_a_provider_cannot_smuggle_content_into_a_higher_layer(): void
    {
        $this->expectException(PromptCompositionException::class);

        (new PromptComposer)->compose(
            constitution: [$this->section(PromptLayer::Context, 'context.injected')],
            policy: [],
            employee: [],
            training: [],
            context: [],
            userMessage: PromptSection::userMessage('مرحبا'),
        );
    }

    public function test_duplicate_section_keys_are_rejected(): void
    {
        $this->expectException(PromptCompositionException::class);

        (new PromptComposer)->compose(
            constitution: [],
            policy: [$this->section(PromptLayer::Policy, 'dup.key')],
            employee: [],
            training: [$this->section(PromptLayer::Training, 'dup.key')],
            context: [],
            userMessage: PromptSection::userMessage('مرحبا'),
        );
    }

    public function test_user_message_is_required(): void
    {
        $this->expectException(PromptCompositionException::class);

        (new PromptComposer)->compose([], [], [], [], [], PromptSection::userMessage('  '));
    }

    public function test_only_constitution_to_training_layers_carry_instructions(): void
    {
        $this->assertTrue(PromptLayer::Constitution->carriesInstructions());
        $this->assertTrue(PromptLayer::Training->carriesInstructions());
        $this->assertFalse(PromptLayer::Context->carriesInstructions());
        $this->assertFalse(PromptLayer::UserMessage->carriesInstructions());
    }

    public function test_composer_source_holds_no_rule_text_or_provider_syntax(): void
    {
        $files = [
            __DIR__.'/../../../../app/AI/Core/PromptComposer.php',
            __DIR__.'/../../../../app/AI/Core/Prompt/PromptLayer.php',
            __DIR__.'/../../../../app/AI/Core/Prompt/PromptSection.php',
            __DIR__.'/../../../../app/AI/Core/Prompt/PromptComposition.php',
        ];

        foreach ($files as $file) {
            $tokens = token_get_all((string) file_get_contents($file));
            foreach ($tokens as $token) {
                if (! is_array($token) || $token[0] !== T_CONSTANT_ENCAPSED_STRING) {
                    continue;
                }
                $literal = trim($token[1], '\'"');
                $this->assertLessThanOrEqual(40, mb_strlen($literal), basename($file).' has a long literal: '.$literal);
                $this->assertDoesNotMatchRegularExpression('/\p{Arabic}/u', $literal, basename($file));
                $this->assertDoesNotMatchRegularExpression('/\b(system_instruction|parts|role|assistant|model)\b/i', $literal, basename($file));
            }
        }
    }

    private function section(PromptLayer $layer, string $key): PromptSection
    {
        return new PromptSection($layer, $key, 'CONTENT:'.$key);
    }
}
