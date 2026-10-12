<?php

declare(strict_types=1);

namespace Tests\Unit\AI\Training;

use App\AI\Core\Prompt\PromptLayer;
use App\AI\Training\TrainingContextBuilder;
use App\AI\Training\TrainingException;
use App\AI\Training\TrainingIssueCode;
use App\AI\Training\TrainingLoader;
use App\AI\Training\TrainingQuery;
use App\AI\Training\TrainingRepository;
use PHPUnit\Framework\TestCase;
use Tests\Support\AI\Training\TrainingFixture;

class TrainingContextBuilderTest extends TestCase
{
    private TrainingFixture $fixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixture = TrainingFixture::empty();
        $f = $this->fixture;
        $f->valid('rules/always_b.md', 'rule.always_b', 'rule', 'Always B guidance.', ['always' => 'true']);
        $f->valid('rules/always_a.md', 'rule.always_a', 'rule', 'Always A guidance.', ['always' => 'true', 'priority' => 'critical']);
        $f->valid('playbooks/profile_pick.md', 'playbook.profile_pick', 'playbook', 'Profile playbook.');
        $f->valid('profiles/retail.md', 'profile.retail', 'profile', 'Retail profile.', ['includes' => '[playbook.profile_pick]']);
        $f->valid('rules/sales_low.md', 'rule.sales_low', 'rule', 'Sales low guidance.', ['priority' => 'low', 'applies_to' => "\n  skills: [sales]"]);
        $f->valid('rules/sales_high.md', 'rule.sales_high', 'rule', 'Sales high guidance.', ['priority' => 'high', 'applies_to' => "\n  skills: [sales]"]);
        $f->valid('rules/sales_plain.md', 'rule.sales_plain', 'rule', 'Sales plain guidance.', ['applies_to' => "\n  skills: [sales]"]);
        $f->valid('rules/sales_fashion.md', 'rule.sales_fashion', 'rule', 'Sales fashion guidance.', ['applies_to' => "\n  skills: [sales]\n  domains: [fashion]"]);
        $f->valid('rules/saudi.md', 'rule.saudi', 'rule', 'Saudi guidance.', ['applies_to' => "\n  dialects: [saudi]"]);
        $f->valid('rules/english.md', 'rule.english', 'rule', 'English guidance.', ['applies_to' => "\n  languages: [en]"]);
        $f->valid('rules/role.md', 'rule.role', 'rule', 'Role guidance.', ['applies_to' => "\n  roles: [sales_agent]"]);
        $f->valid('rules/fashion.md', 'rule.fashion', 'rule', 'Fashion guidance.', ['applies_to' => "\n  domains: [fashion]"]);
        $f->valid('rules/unreachable.md', 'rule.unreachable', 'rule', 'Only via profiles.');
        $f->valid('examples/short/kam.md', 'example.kam', 'example', $this->exampleBody('كم'), ['applies_to' => "\n  intents: [short_message]"]);
        $f->valid('scenarios/discount.md', 'scenario.discount', 'scenario', $this->scenarioBody(), ['applies_to' => "\n  intents: [discount_request]"]);
    }

    protected function tearDown(): void
    {
        $this->fixture->remove();
        parent::tearDown();
    }

    public function test_always_rules_are_included_for_any_query(): void
    {
        $this->assertSame(['rule.always_a', 'rule.always_b'], $this->builder()->plan(new TrainingQuery)->ids());
    }

    public function test_full_precedence_order_across_tiers(): void
    {
        $plan = $this->builder()->plan(new TrainingQuery(
            profile: 'retail',
            skills: ['sales'],
            intents: ['short_message'],
            roles: ['sales_agent'],
            dialect: 'saudi',
            language: 'en',
            domains: ['fashion'],
        ));

        $this->assertSame([
            'rule.always_a', 'rule.always_b',
            'playbook.profile_pick',
            'rule.sales_high', 'rule.sales_fashion', 'rule.sales_plain', 'rule.sales_low',
            'rule.saudi',
            'rule.english',
            'rule.role',
            'rule.fashion',
            'example.kam',
        ], $plan->ids());
    }

    public function test_matching_skill_and_intent_are_included_and_irrelevant_material_is_not(): void
    {
        $ids = $this->builder()->plan(new TrainingQuery(skills: ['sales'], intents: ['short_message']))->ids();

        $this->assertContains('rule.sales_high', $ids);
        $this->assertContains('example.kam', $ids);
        $this->assertNotContains('scenario.discount', $ids);
        $this->assertNotContains('rule.saudi', $ids);
        $this->assertNotContains('rule.unreachable', $ids);
        $this->assertNotContains('rule.sales_fashion', $ids);
        $this->assertNotContains('playbook.profile_pick', $ids);
    }

    public function test_selection_is_deterministic(): void
    {
        $query = new TrainingQuery(profile: 'retail', skills: ['sales'], intents: ['short_message'], dialect: 'saudi');

        $first = $this->builder()->plan($query);
        $second = $this->builder()->plan($query);

        $this->assertSame($first->ids(), $second->ids());
        $this->assertSame($first->version(), $second->version());
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $first->version());
    }

    public function test_budget_skips_what_does_not_fit_and_keeps_order(): void
    {
        $always = mb_strlen('Always A guidance.') + mb_strlen('Always B guidance.');
        $budget = max(500, $always + mb_strlen('Sales high guidance.'));
        $this->fixture->valid('rules/sales_high.md', 'rule.sales_high', 'rule', str_repeat('x', 470), ['priority' => 'high', 'applies_to' => "\n  skills: [sales]"]);

        $plan = $this->builder()->plan(new TrainingQuery(skills: ['sales'], budgetChars: $budget));

        $this->assertLessThanOrEqual($budget, $plan->usedChars);
        $this->assertSame(['rule.always_a', 'rule.always_b'], array_slice($plan->ids(), 0, 2));
        $this->assertContains('rule.sales_high', $plan->omittedIds);
        $this->assertNotContains('rule.sales_high', $plan->ids());
        $this->assertContains('rule.sales_plain', $plan->ids(), 'A smaller document after a skipped one still fits.');
        $this->assertSame(array_sum(array_map(static fn ($d): int => $d->characters(), $plan->documents)), $plan->usedChars);
    }

    public function test_always_rules_that_exceed_the_budget_fail_instead_of_being_dropped(): void
    {
        $this->fixture->valid('rules/always_a.md', 'rule.always_a', 'rule', str_repeat('a', 600), ['always' => 'true', 'priority' => 'critical']);

        try {
            $this->builder()->plan(new TrainingQuery(budgetChars: 500));
            $this->fail('Always-on rules were silently dropped.');
        } catch (TrainingException $exception) {
            $this->assertSame(TrainingIssueCode::BudgetExceeded, $exception->issue);
        }
    }

    public function test_unknown_profile_fails(): void
    {
        $this->expectException(TrainingException::class);

        $this->builder()->plan(new TrainingQuery(profile: 'missing'));
    }

    public function test_selection_maps_to_core_training_layer_with_versions(): void
    {
        $selection = $this->builder()->build(new TrainingQuery(skills: ['sales']));

        $this->assertSame(PromptLayer::Training, $selection->sections[0]->layer);
        $this->assertSame('training.rule.always_a', $selection->sections[0]->key);
        $this->assertSame('training/rules/always_a.md', $selection->sections[0]->source);
        $this->assertSame('Always A guidance.', $selection->sections[0]->content);
        $this->assertSame('rule.always_a', $selection->versions[0]->id);
        $this->assertMatchesRegularExpression('/^1:[0-9a-f]{16}$/', $selection->versions[0]->version);
        $this->assertCount(count($selection->sections), $selection->versions);
    }

    public function test_selection_version_changes_with_selected_content_only(): void
    {
        $query = new TrainingQuery(skills: ['sales']);
        $before = $this->builder()->plan($query)->version();

        $this->fixture->valid('rules/saudi.md', 'rule.saudi', 'rule', 'Saudi guidance changed.', ['applies_to' => "\n  dialects: [saudi]"]);
        $unrelated = $this->builder()->plan($query)->version();

        $this->fixture->valid('rules/sales_plain.md', 'rule.sales_plain', 'rule', 'Sales plain guidance changed.', ['applies_to' => "\n  skills: [sales]"]);
        $related = $this->builder()->plan($query)->version();

        $this->assertSame($before, $unrelated);
        $this->assertNotSame($before, $related);
    }

    public function test_query_rejects_invalid_selectors_and_budgets(): void
    {
        foreach ([
            fn () => new TrainingQuery(skills: ['Sales Team']),
            fn () => new TrainingQuery(dialect: '../saudi'),
            fn () => new TrainingQuery(budgetChars: 10),
            fn () => new TrainingQuery(budgetChars: 999_999),
        ] as $factory) {
            try {
                $factory();
                $this->fail('Invalid query was accepted.');
            } catch (TrainingException $exception) {
                $this->assertSame(TrainingIssueCode::InvalidQuery, $exception->issue);
            }
        }
    }

    private function builder(): TrainingContextBuilder
    {
        return new TrainingContextBuilder(new TrainingRepository(TrainingLoader::forDirectory($this->fixture->directory)));
    }

    private function exampleBody(string $customer): string
    {
        return "## Customer\n{$customer}\n\n## Expected behavior\nUse context.\n\n## Good response\nسعره 10 ريال\n\n## Bad response\nوش تقصد؟\n\n## Why\nContext is enough.";
    }

    private function scenarioBody(): string
    {
        return "## Context\nx\n\n## Customer intent\ny\n\n## Expected behavior\nz\n\n## Allowed actions\na\n\n## Forbidden behavior\nb\n\n## Good example\nc\n\n## Bad example\nd";
    }
}
