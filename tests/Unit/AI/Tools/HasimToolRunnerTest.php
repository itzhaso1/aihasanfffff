<?php

declare(strict_types=1);

namespace Tests\Unit\AI\Tools;

use App\AI\Core\AiInboundMessage;
use App\AI\Core\AiRequest;
use App\AI\Core\AiRunMode;
use App\AI\Core\Data\ModelToolCall;
use App\AI\Core\Data\ResolvedEmployee;
use App\AI\Tools\CanonicalTools;
use App\AI\Tools\EmployeeToolGrant;
use App\AI\Tools\HasimToolRunner;
use App\AI\Tools\RuntimeToolResult;
use App\AI\Tools\StaticToolPermissionSource;
use App\AI\Tools\ToolDenial;
use App\AI\Tools\ToolPolicy;
use App\AI\Tools\ToolRegistry;
use PHPUnit\Framework\TestCase;
use Tests\Support\AI\Tools\FakeRuntimeTool;

class HasimToolRunnerTest extends TestCase
{
    public function test_available_tools_follow_skill_and_sandbox_policy(): void
    {
        $runner = $this->runner();

        $names = array_map(
            static fn ($spec): string => $spec->name,
            $runner->availableTools($this->request(AiRunMode::Preview), $this->employee(['sales'])),
        );

        $this->assertSame([
            CanonicalTools::EVALUATE_PRODUCT_SUITABILITY,
            CanonicalTools::GET_PRODUCT_INTELLIGENCE,
            CanonicalTools::SEARCH_PRODUCTS,
        ], $names);
    }

    public function test_successful_search_records_facts_and_keeps_catalog_item_id(): void
    {
        $outcome = $this->runner()->run(
            $this->request(AiRunMode::Live),
            $this->employee(['sales']),
            new ModelToolCall('c1', CanonicalTools::SEARCH_PRODUCTS, ['query' => 'فستان']),
        );

        $this->assertTrue($outcome->record->success);
        $this->assertSame(12, $outcome->modelPayload['products'][0]['catalog_item_id']);
        $this->assertArrayNotHasKey('customer_id', $outcome->modelPayload);
        $name = collect($outcome->facts)->first(static fn ($fact): bool => $fact->key === 'catalog_item:12.name');

        $this->assertNotNull($name);
        $this->assertSame('search_products', $outcome->facts[0]->source);
        $this->assertNull($outcome->confirmedAction);
    }

    public function test_live_save_records_a_confirmed_action(): void
    {
        $outcome = $this->runner()->run(
            $this->request(AiRunMode::Live),
            $this->employee(['sales']),
            new ModelToolCall('c2', CanonicalTools::SAVE_CUSTOMER_COMMERCE_CONTEXT, [
                'context_attributes' => ['bust_cm' => 90],
            ]),
        );

        $this->assertTrue($outcome->record->success);
        $this->assertSame(CanonicalTools::SAVE_CUSTOMER_COMMERCE_CONTEXT, $outcome->confirmedAction?->type);
        $this->assertSame('4', $outcome->confirmedAction?->reference);
        $this->assertTrue($outcome->facts[0]->value);
    }

    public function test_forbidden_identity_argument_is_rejected_before_execution(): void
    {
        $executed = false;
        $runner = $this->runner(searchHandler: function () use (&$executed) {
            $executed = true;

            return RuntimeToolResult::ok([]);
        });

        $outcome = $runner->run(
            $this->request(AiRunMode::Live),
            $this->employee(['sales']),
            new ModelToolCall('c3', CanonicalTools::SEARCH_PRODUCTS, [
                'query' => 'فستان',
                'workspace_id' => 99,
            ]),
        );

        $this->assertFalse($executed);
        $this->assertFalse($outcome->record->success);
        $this->assertSame(ToolDenial::FORBIDDEN_ARGUMENT, $outcome->record->errorCode);
    }

    public function test_unknown_and_unskilled_calls_are_not_offered(): void
    {
        $runner = $this->runner();

        $unknown = $runner->run(
            $this->request(AiRunMode::Live),
            $this->employee(['sales']),
            new ModelToolCall('c4', 'refund_invoice', []),
        );
        $faq = $runner->run(
            $this->request(AiRunMode::Live),
            $this->employee(['faq']),
            new ModelToolCall('c5', CanonicalTools::SEARCH_PRODUCTS, ['query' => 'فستان']),
        );

        $this->assertSame(ToolDenial::UNKNOWN, $unknown->record->errorCode);
        $this->assertSame(ToolDenial::SKILL_DENIED, $faq->record->errorCode);
    }

    public function test_sandbox_cannot_write_and_budget_limits_repeat_calls(): void
    {
        $runner = $this->runner();
        $preview = $this->request(AiRunMode::Preview);
        $live = $this->request(AiRunMode::Live);
        $employee = $this->employee(['sales']);

        $write = $runner->run(
            $preview,
            $employee,
            new ModelToolCall('c6', CanonicalTools::SAVE_CUSTOMER_COMMERCE_CONTEXT, [
                'context_attributes' => ['bust_cm' => 90],
            ]),
        );
        $this->assertSame(ToolDenial::SANDBOX_WRITE, $write->record->errorCode);

        $first = $runner->run($live, $employee, new ModelToolCall('c7', CanonicalTools::SEARCH_PRODUCTS, ['query' => 'فستان']));
        $second = $runner->run($live, $employee, new ModelToolCall('c8', CanonicalTools::SEARCH_PRODUCTS, ['query' => 'فستان']));
        $third = $runner->run($live, $employee, new ModelToolCall('c9', CanonicalTools::SEARCH_PRODUCTS, ['query' => 'فستان']));

        $this->assertTrue($first->record->success);
        $this->assertTrue($second->record->success);
        $this->assertSame(ToolDenial::DUPLICATE_CALL, $third->record->errorCode);
    }

    public function test_explicit_deny_blocks_an_otherwise_skilled_employee(): void
    {
        $runner = $this->runner(grants: [new EmployeeToolGrant(CanonicalTools::SEARCH_PRODUCTS, false)]);

        $outcome = $runner->run(
            $this->request(AiRunMode::Live),
            $this->employee(['sales']),
            new ModelToolCall('c10', CanonicalTools::SEARCH_PRODUCTS, ['query' => 'فستان']),
        );

        $this->assertSame(ToolDenial::PERMISSION_DENIED, $outcome->record->errorCode);
    }

    /**
     * @param  list<EmployeeToolGrant>  $grants
     * @param  ?callable(AiRequest, ResolvedEmployee, array<string, mixed>): RuntimeToolResult  $searchHandler
     */
    private function runner(array $grants = [], ?callable $searchHandler = null): HasimToolRunner
    {
        return new HasimToolRunner(
            new ToolRegistry([
                new FakeRuntimeTool(
                    CanonicalTools::searchProducts(),
                    $searchHandler,
                    RuntimeToolResult::ok([
                        'count' => 1,
                        'customer_id' => 77,
                        'products' => [[
                            'catalog_item_id' => 12,
                            'name' => 'فستان أسود',
                            'price' => 10,
                            'stock' => 3,
                            'workspace_id' => 9,
                        ]],
                    ]),
                ),
                new FakeRuntimeTool(CanonicalTools::getProductIntelligence(), result: RuntimeToolResult::ok(['item_id' => 12])),
                new FakeRuntimeTool(CanonicalTools::evaluateProductSuitability(), result: RuntimeToolResult::ok(['item_id' => 12, 'evaluation' => ['status' => 'suitable']])),
                new FakeRuntimeTool(CanonicalTools::saveCustomerCommerceContext(), result: RuntimeToolResult::ok([
                    'saved' => true,
                    'keys' => ['bust_cm'],
                    'customer_id' => 77,
                ])),
            ]),
            new ToolPolicy,
            new StaticToolPermissionSource([3 => $grants]),
        );
    }

    /**
     * @param  list<string>  $skills
     */
    private function employee(array $skills): ResolvedEmployee
    {
        return new ResolvedEmployee(3, skillKeys: $skills);
    }

    private function request(AiRunMode $mode): AiRequest
    {
        return new AiRequest(
            workspaceId: 9,
            conversationId: $mode === AiRunMode::Live ? 4 : null,
            employeeId: 3,
            message: new AiInboundMessage('كم'),
            runMode: $mode,
        );
    }
}
