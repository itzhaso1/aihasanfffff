<?php

declare(strict_types=1);

namespace Tests\Unit\AI\Tools;

use App\AI\Core\AiInboundMessage;
use App\AI\Core\AiRequest;
use App\AI\Core\AiRunMode;
use App\AI\Core\Data\ModelToolCall;
use App\AI\Core\Data\ResolvedEmployee;
use App\AI\Tools\Adapters\InboxToolAdapter;
use App\AI\Tools\Adapters\InboxToolContextFactory;
use App\AI\Tools\CanonicalTools;
use App\AI\Tools\HasimToolRunner;
use App\AI\Tools\StaticToolPermissionSource;
use App\AI\Tools\ToolDenial;
use App\AI\Tools\ToolPolicy;
use App\AI\Tools\ToolRegistry;
use App\Models\Workspace;
use App\Services\AI\Inbox\InboxAgentContext;
use App\Services\AI\Inbox\Tools\Contracts\InboxAgentTool;
use App\Services\AI\Inbox\Tools\InboxAgentToolExecutionResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InboxToolAdapterTest extends TestCase
{
    use RefreshDatabase;

    public function test_adapter_executes_the_existing_search_tool_inside_the_request_workspace(): void
    {
        $workspace = Workspace::factory()->create();
        $seen = (object) ['workspace_id' => null];
        $search = new class($seen) implements InboxAgentTool
        {
            public function __construct(private object $seen) {}

            public function name(): string
            {
                return CanonicalTools::SEARCH_PRODUCTS;
            }

            public function description(): string
            {
                return 'Search';
            }

            public function inputSchema(): array
            {
                return CanonicalTools::searchProducts()->inputSchema;
            }

            public function execute(InboxAgentContext $context, array $arguments): InboxAgentToolExecutionResult
            {
                $this->seen->workspace_id = (int) $context->workspace->id;

                return InboxAgentToolExecutionResult::success(
                    CanonicalTools::SEARCH_PRODUCTS,
                    'search_products',
                    [
                        'query' => $arguments['query'],
                        'count' => 1,
                        'customer_id' => 77,
                        'products' => [[
                            'catalog_item_id' => 21,
                            'name' => 'فستان أسود',
                            'price' => 10,
                            'stock' => 2,
                        ]],
                    ],
                );
            }
        };

        $runner = new HasimToolRunner(
            new ToolRegistry([
                new InboxToolAdapter($search, CanonicalTools::searchProducts(), new InboxToolContextFactory),
            ]),
            new ToolPolicy,
            new StaticToolPermissionSource,
        );

        $outcome = $runner->run(
            new AiRequest(
                workspaceId: (int) $workspace->id,
                conversationId: 5,
                employeeId: 3,
                message: new AiInboundMessage('فستان'),
                runMode: AiRunMode::Live,
            ),
            new ResolvedEmployee(3, skillKeys: ['sales']),
            new ModelToolCall('c1', CanonicalTools::SEARCH_PRODUCTS, ['query' => 'فستان']),
        );

        $this->assertTrue($outcome->record->success);
        $this->assertSame((int) $workspace->id, $seen->workspace_id);
        $this->assertSame(21, $outcome->modelPayload['products'][0]['catalog_item_id']);
        $this->assertArrayNotHasKey('customer_id', $outcome->modelPayload);
        $name = collect($outcome->facts)->first(static fn ($fact): bool => $fact->key === 'catalog_item:21.name');

        $this->assertNotNull($name);
    }

    public function test_missing_workspace_is_denied(): void
    {
        $search = new class implements InboxAgentTool
        {
            public function name(): string
            {
                return CanonicalTools::SEARCH_PRODUCTS;
            }

            public function description(): string
            {
                return 'Search';
            }

            public function inputSchema(): array
            {
                return CanonicalTools::searchProducts()->inputSchema;
            }

            public function execute(InboxAgentContext $context, array $arguments): InboxAgentToolExecutionResult
            {
                throw new \RuntimeException('Existing tool must not run without a workspace.');
            }
        };

        $runner = new HasimToolRunner(
            new ToolRegistry([
                new InboxToolAdapter($search, CanonicalTools::searchProducts(), new InboxToolContextFactory),
            ]),
            new ToolPolicy,
            new StaticToolPermissionSource,
        );

        $outcome = $runner->run(
            new AiRequest(
                workspaceId: 999999,
                conversationId: 5,
                employeeId: 3,
                message: new AiInboundMessage('فستان'),
                runMode: AiRunMode::Live,
            ),
            new ResolvedEmployee(3, skillKeys: ['sales']),
            new ModelToolCall('c1', CanonicalTools::SEARCH_PRODUCTS, ['query' => 'فستان']),
        );

        $this->assertFalse($outcome->record->success);
        $this->assertSame(ToolDenial::WORKSPACE_DENIED, $outcome->record->errorCode);
    }
}
