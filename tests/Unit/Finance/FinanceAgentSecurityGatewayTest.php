<?php

namespace Tests\Unit\Finance;

use App\Services\Finance\Assistant\Agent\FinanceAgentGrounding;
use App\Services\Finance\Assistant\Context\FinanceAgentStructuredContext;
use App\Services\Finance\Assistant\FinanceAssistantToolRegistry;
use App\Services\Finance\Assistant\Security\FinanceAgentToolArgumentSecurity;
use App\Services\Finance\Assistant\Security\FinanceAgentToolSecurityCatalog;
use Tests\TestCase;

class FinanceAgentSecurityGatewayTest extends TestCase
{
    public function test_security_catalog_matches_registry(): void
    {
        $registry = app(FinanceAssistantToolRegistry::class);
        $names = array_map(fn ($tool) => $tool->name(), $registry->all());

        app(FinanceAgentToolSecurityCatalog::class)->assertRegistryCovered($names);
        $this->assertGreaterThanOrEqual(35, count($names));
        $this->assertContains('customers.invoices', $names);
        $this->assertContains('invoices.by_customer', $names);
    }

    public function test_identity_arguments_are_stripped_not_applied(): void
    {
        $result = app(FinanceAgentToolArgumentSecurity::class)->sanitize([
            'preset' => 'month',
            'workspace_id' => 99,
            'customer_id' => 5,
            'permission' => 'finance.view',
        ]);

        $this->assertSame(['preset' => 'month'], $result['arguments']);
        $this->assertContains('workspace_id', $result['stripped']);
        $this->assertNull($result['decision']);
    }

    public function test_oversized_argument_is_rejected(): void
    {
        config(['finance_assistant.security.max_argument_string' => 20]);
        $result = app(FinanceAgentToolArgumentSecurity::class)->sanitize([
            'query' => str_repeat('a', 40),
        ]);

        $this->assertNotNull($result['decision']);
        $this->assertSame('argument_string', $result['decision']->reasonCode);
    }

    public function test_grounding_accepts_arabic_indic_and_grouped_numbers(): void
    {
        $grounding = app(FinanceAgentGrounding::class);
        $results = [[
            'available' => true,
            'value' => '5000.00',
        ]];

        $this->assertTrue($grounding->answerIsGrounded('المبلغ ٥٬٠٠٠٫٠٠', $results) || $grounding->answerIsGrounded('المبلغ 5,000.00', $results));
        $this->assertTrue($grounding->answerIsGrounded('المبلغ 5,000.00 ريال', $results));
        $this->assertTrue($grounding->answerIsGrounded('المبلغ 5000', [[
            'available' => true,
            'value' => '5000.00',
        ]]));
        $this->assertTrue($grounding->answerIsGrounded('التغير -12.50', [[
            'available' => true,
            'value' => '-12.50',
        ]]));
        $this->assertFalse($grounding->answerIsGrounded('المبلغ 7777.00', $results));
    }

    public function test_context_fingerprint_changes_when_identity_or_period_changes(): void
    {
        $base = new FinanceAgentStructuredContext(1, 2, 3, 'ar', '2026-09-01', '2026-09-30', ['sales.period'], ['user'], 1, 'fai_a');
        $this->assertNotSame($base->fingerprint(), (new FinanceAgentStructuredContext(9, 2, 3, 'ar', '2026-09-01', '2026-09-30', ['sales.period'], ['user'], 1, 'fai_a'))->fingerprint());
        $this->assertNotSame($base->fingerprint(), (new FinanceAgentStructuredContext(1, 8, 3, 'ar', '2026-09-01', '2026-09-30', ['sales.period'], ['user'], 1, 'fai_a'))->fingerprint());
        $this->assertNotSame($base->fingerprint(), (new FinanceAgentStructuredContext(1, 2, 4, 'ar', '2026-09-01', '2026-09-30', ['sales.period'], ['user'], 1, 'fai_a'))->fingerprint());
        $this->assertNotSame($base->fingerprint(), (new FinanceAgentStructuredContext(1, 2, 3, 'ar', '2026-08-01', '2026-08-31', ['sales.period'], ['user'], 1, 'fai_a'))->fingerprint());
        $this->assertNotSame($base->fingerprint(), (new FinanceAgentStructuredContext(1, 2, 3, 'ar', '2026-09-01', '2026-09-30', ['profit.net'], ['user'], 1, 'fai_a'))->fingerprint());
        $this->assertStringNotContainsString('fai_a', $base->fingerprint());
    }

    public function test_context_fingerprint_is_stable(): void
    {
        $context = new FinanceAgentStructuredContext(
            workspaceId: 1,
            userId: 2,
            conversationId: 3,
            locale: 'ar',
            periodFrom: '2026-09-01',
            periodTo: '2026-09-30',
            metricNames: ['sales.period'],
            historyRoles: ['user', 'assistant'],
            historyCount: 2,
            correlationId: 'fai_test',
        );

        $this->assertSame($context->fingerprint(), $context->fingerprint());
        $this->assertSame(64, strlen($context->fingerprint()));
    }
}
