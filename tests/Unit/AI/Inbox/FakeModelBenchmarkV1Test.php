<?php

declare(strict_types=1);

namespace Tests\Unit\AI\Inbox;

use App\AI\Constitution\ConstitutionLoader;
use App\AI\Context\AiContextProvider;
use App\AI\Employees\ConversationEmployeeBinder;
use App\AI\Employees\DatabaseEmployeeResolver;
use App\AI\Employees\EmployeeRepository;
use App\AI\Employees\EmployeeTrainingQueryFactory;
use App\AI\Evaluation\HasimEvaluator;
use App\AI\Guard\HasimResponseGuard;
use App\AI\Inbox\HasimInboxRuntime;
use App\AI\Inbox\InboxPolicyProvider;
use App\AI\Tools\Adapters\InboxToolAdapters;
use App\AI\Tools\DatabaseToolPermissionSource;
use App\AI\Tools\HasimToolRunner;
use App\AI\Tools\ToolPolicy;
use App\AI\Training\LibraryTrainingProvider;
use App\AI\Training\TrainingContextBuilder;
use App\Models\AiLog;
use App\Models\Message;
use App\Services\AI\AiSafety;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\AI\Benchmark\BenchmarkEvaluatorContract;
use Tests\Support\AI\Benchmark\GoldCaseSelector;
use Tests\Support\AI\Core\FakeBenchmarkModel;
use Tests\Support\AI\Inbox\CreatesHasimInboxFixtures;
use Tests\TestCase;

class FakeModelBenchmarkV1Test extends TestCase
{
    use CreatesHasimInboxFixtures;
    use RefreshDatabase;

    public function test_fake_model_benchmark_runs_fifteen_hundred_cases_on_sqlite(): void
    {
        $this->assertSame('sqlite', (string) config('database.default'));
        $this->assertSame(':memory:', (string) config('database.connections.sqlite.database'));

        $root = dirname(__DIR__, 4);
        $cases = (new GoldCaseSelector)->select($root);
        $this->assertCount(1500, $cases);
        $this->assertCount(50, array_unique(array_column($cases, 'part')));

        [, $conversation, $template, $employee] = $this->seedHasimInbox([
            'skills' => ['sales', 'product_search'],
        ]);
        $model = new FakeBenchmarkModel;
        $this->bindRuntime($model);

        $results = [];
        $started = microtime(true);
        $errors = 0;
        $guardRuns = 0;
        foreach ($cases as $case) {
            $message = $this->replaceContent($template, (string) $case['question']);
            try {
                $result = app(HasimInboxRuntime::class)->reply($conversation, $message);
                $failure = $result->response?->decision->failure;
                $completed = $failure === null && $result->response !== null;
                if (! $completed) {
                    $errors++;
                }
                if ($result->response?->decision->guardResults !== []) {
                    $guardRuns++;
                }
                $results[] = [
                    'benchmark_id' => $case['benchmark_id'],
                    'status' => $completed ? 'completed' : 'error',
                    'model_invoked' => true,
                    'guard_invoked' => $result->response?->decision->guardResults !== [],
                    'tool_invocations' => count($result->response?->decision->toolCalls ?? []),
                    'employee_id' => $result->response?->decision->employeeId,
                    'failure_layer' => $failure?->stage->value,
                    'failure_reason' => $failure?->code->value,
                    'response_source' => 'fake_model',
                ];
            } catch (\Throwable $exception) {
                $errors++;
                $results[] = [
                    'benchmark_id' => $case['benchmark_id'],
                    'status' => 'error',
                    'model_invoked' => false,
                    'guard_invoked' => false,
                    'tool_invocations' => 0,
                    'employee_id' => null,
                    'failure_layer' => 'ENVIRONMENT',
                    'failure_reason' => $exception::class,
                    'response_source' => 'fake_model',
                ];
            }
        }
        $elapsed = (int) round((microtime(true) - $started) * 1000);

        $this->assertSame((int) $employee->id, $results[0]['employee_id']);
        $this->assertSame(1500, count($model->requests));
        $this->assertSame(0, AiLog::withoutGlobalScopes()->count());
        $this->assertSame(0, $errors);
        $this->assertSame(1500, $guardRuns);
        $this->assertNotSame([], $model->requests[0]->tools);

        $evaluator = new BenchmarkEvaluatorContract;
        $fixtures = [
            ['id' => 'known-pass', 'actual' => 'intent:invoice domain:payment', 'expect_intent' => 'invoice', 'expect_domain' => 'payment'],
            ['id' => 'semantic-mismatch', 'actual' => 'intent:other domain:payment', 'expect_intent' => 'invoice', 'expect_domain' => 'payment'],
            ['id' => 'unnecessary-clarification', 'actual' => 'وش تقصد؟', 'unnecessary_clarification' => true],
            ['id' => 'fabricated-fact', 'actual' => 'السعر 50 ريال', 'fabricated' => true],
            ['id' => 'wrong-intent', 'actual' => 'intent:greeting', 'expect_intent' => 'invoice'],
            ['id' => 'wrong-domain', 'actual' => 'domain:food', 'expect_domain' => 'payment'],
            ['id' => 'wrong-decision', 'actual' => 'جواب', 'decision' => 'mismatch'],
            ['id' => 'saudi-mismatch', 'actual' => 'Hello there', 'style' => 'mismatch'],
        ];
        $classified = array_map($evaluator->classify(...), $fixtures);
        $this->assertSame('PASS', $classified[0]['status']);
        $this->assertContains('wrong_intent', $classified[1]['issues']);
        $this->assertContains('unnecessary_clarification', $classified[2]['issues']);
        $this->assertContains('fabricated_fact', $classified[3]['issues']);

        $dir = $root.'/ai-benchmark/runtime-benchmark-v1';
        if (! is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        file_put_contents($dir.'/manifest.json', json_encode([
            'benchmark_version' => 'v1-fake-model',
            'selection' => 'first 30 valid records of each of 50 documented parts',
            'parts' => 50,
            'per_part' => 30,
            'cases' => 1500,
            'model' => 'fake_benchmark',
            'database' => 'sqlite :memory:',
            'quality_claim' => false,
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        $this->writeJsonl($dir.'/selected_cases.jsonl', $cases);
        $this->writeJsonl($dir.'/runtime_results.jsonl', $results);
        file_put_contents($dir.'/infrastructure_summary.json', json_encode([
            'started' => 1500,
            'completed' => 1500 - $errors,
            'errors' => $errors,
            'model_invocations' => count($model->requests),
            'guard_invocations' => $guardRuns,
            'tool_invocations' => array_sum(array_column($results, 'tool_invocations')),
            'duration_ms' => $elapsed,
            'note' => 'Infrastructure only. Not an AI quality score.',
        ], JSON_PRETTY_PRINT));
        file_put_contents($dir.'/evaluator_contract_results.json', json_encode($classified, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        file_put_contents($dir.'/benchmark_report.md', $this->report($elapsed, $errors));
    }

    public function test_fake_model_can_request_one_deterministic_tool_call(): void
    {
        $model = new FakeBenchmarkModel(true);
        $root = dirname(__DIR__, 4);
        $case = (new GoldCaseSelector)->select($root)[0];
        [, $conversation, $template] = $this->seedHasimInbox(['skills' => ['sales', 'product_search']]);
        $this->bindRuntime($model);
        $result = app(HasimInboxRuntime::class)->reply($conversation, $this->replaceContent($template, (string) $case['question']));

        $this->assertNotSame([], $result->response?->decision->toolCalls ?? []);
        $this->assertSame('search_products', $result->response->decision->toolCalls[0]->toolName);
    }

    private function bindRuntime(FakeBenchmarkModel $model): void
    {
        $this->app->instance(HasimInboxRuntime::class, new HasimInboxRuntime(
            app(DatabaseEmployeeResolver::class),
            app(ConstitutionLoader::class),
            new InboxPolicyProvider,
            app(AiContextProvider::class),
            new LibraryTrainingProvider(app(TrainingContextBuilder::class), app(EmployeeTrainingQueryFactory::class)),
            $model,
            new HasimToolRunner(
                app(InboxToolAdapters::class)->registry(),
                new ToolPolicy,
                app(DatabaseToolPermissionSource::class),
            ),
            new HasimResponseGuard,
            app(ConversationEmployeeBinder::class),
            new HasimEvaluator,
            app(EmployeeRepository::class),
            app(AiSafety::class),
        ));
    }

    private function replaceContent(Message $template, string $question): Message
    {
        $copy = $template->replicate();
        $copy->content = $question;
        $copy->save();

        return $copy;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function writeJsonl(string $path, array $rows): void
    {
        $handle = fopen($path, 'wb');
        $this->assertNotFalse($handle);
        foreach ($rows as $row) {
            fwrite($handle, json_encode($row, JSON_UNESCAPED_UNICODE)."\n");
        }
        fclose($handle);
    }

    private function report(int $elapsed, int $errors): string
    {
        return <<<MD
# Fake-model runtime benchmark V1

This run validates infrastructure only. It is not an AI quality, accuracy, or Gemini result.

- Cases: 1500
- Selection: first 30 records of each of 50 documented parts
- Model: deterministic fake gateway, no HTTP
- Database: sqlite :memory:
- Completed: {$this->completed($errors)}
- Errors: {$errors}
- Duration ms: {$elapsed}

MD;
    }

    private function completed(int $errors): int
    {
        return 1500 - $errors;
    }
}
