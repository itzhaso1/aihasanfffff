<?php

declare(strict_types=1);

namespace Tests\Unit\AI\Inbox;

use App\AI\Constitution\ConstitutionLoader;
use App\AI\Context\AiContextProvider;
use App\AI\Core\AiRunMode;
use App\AI\Core\Data\ModelResponse;
use App\AI\Core\Data\ResolvedEmployee;
use App\AI\Employees\ConversationEmployeeBinder;
use App\AI\Employees\DatabaseEmployeeResolver;
use App\AI\Employees\EmployeeRepository;
use App\AI\Employees\EmployeeTrainingQueryFactory;
use App\AI\Evaluation\HasimEvaluator;
use App\AI\Guard\HasimResponseGuard;
use App\AI\Inbox\HasimInboxRuntime;
use App\AI\Inbox\InboxPolicyProvider;
use App\AI\Tools\Adapters\InboxToolAdapters;
use App\AI\Tools\CanonicalTools;
use App\AI\Tools\DatabaseToolPermissionSource;
use App\AI\Tools\HasimToolRunner;
use App\AI\Tools\ToolPolicy;
use App\AI\Training\LibraryTrainingProvider;
use App\AI\Training\TrainingContextBuilder;
use App\Models\AiLog;
use App\Services\AI\AiSafety;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\AI\Core\ScriptedModelGateway;
use Tests\Support\AI\Inbox\CreatesHasimInboxFixtures;
use Tests\TestCase;

class BenchmarkIsolationTest extends TestCase
{
    use CreatesHasimInboxFixtures;
    use RefreshDatabase;

    public function test_live_runtime_runs_on_sqlite_without_production_logging(): void
    {
        $this->assertSame('sqlite', (string) config('database.default'));
        $this->assertSame(':memory:', (string) config('database.connections.sqlite.database'));

        [, $conversation, $message, $employee] = $this->seedHasimInbox([
            'skills' => ['sales', 'product_search'],
        ]);
        $gateway = new ScriptedModelGateway([new ModelResponse('ما فيه منتجات مضافة حالياً.')]);
        $tools = new HasimToolRunner(
            app(InboxToolAdapters::class)->registry(),
            new ToolPolicy,
            app(DatabaseToolPermissionSource::class),
        );
        $guard = new HasimResponseGuard;
        $this->app->instance(HasimInboxRuntime::class, new HasimInboxRuntime(
            app(DatabaseEmployeeResolver::class),
            app(ConstitutionLoader::class),
            new InboxPolicyProvider,
            app(AiContextProvider::class),
            new LibraryTrainingProvider(app(TrainingContextBuilder::class), app(EmployeeTrainingQueryFactory::class)),
            $gateway,
            $tools,
            $guard,
            app(ConversationEmployeeBinder::class),
            new HasimEvaluator,
            app(EmployeeRepository::class),
            app(AiSafety::class),
        ));

        $result = app(HasimInboxRuntime::class)->reply($conversation, $message);

        $this->assertNotNull($result->request);
        $this->assertSame(AiRunMode::Live, $result->request->runMode);
        $this->assertSame((int) $employee->id, $result->response?->decision->employeeId);
        $this->assertNotSame([], $result->response?->decision->guardResults ?? []);
        $this->assertSame(0, AiLog::withoutGlobalScopes()->count());

        $offered = array_map(
            static fn ($spec): string => $spec->name,
            $tools->availableTools($result->request, new ResolvedEmployee(
                employeeId: (int) $employee->id,
                skillKeys: $employee->skillKeys(),
            )),
        );
        $this->assertContains(CanonicalTools::SEARCH_PRODUCTS, $offered);
        $this->assertContains(CanonicalTools::SAVE_CUSTOMER_COMMERCE_CONTEXT, $offered);
        $this->assertSame(':memory:', (string) config('database.connections.sqlite.database'));
    }
}
