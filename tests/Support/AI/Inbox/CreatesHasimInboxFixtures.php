<?php

declare(strict_types=1);

namespace Tests\Support\AI\Inbox;

use App\AI\Constitution\ConstitutionLoader;
use App\AI\Context\AiContextProvider;
use App\AI\Core\Data\ModelResponse;
use App\AI\Employees\ConversationEmployeeBinder;
use App\AI\Employees\DatabaseEmployeeResolver;
use App\AI\Employees\EmployeeRepository;
use App\AI\Employees\EmployeeTrainingQueryFactory;
use App\AI\Employees\Models\AiEmployee;
use App\AI\Evaluation\HasimEvaluator;
use App\AI\Inbox\HasimInboxRuntime;
use App\AI\Inbox\InboxPolicyProvider;
use App\AI\Training\LibraryTrainingProvider;
use App\AI\Training\TrainingContextBuilder;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Message;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Workspace;
use App\Services\AI\AiSafety;
use App\Services\AI\Providers\GoogleAiStudioProvider;
use App\Services\AI\Providers\OpenAiProvider;
use App\Support\Tenancy\WorkspaceContext;
use Tests\Support\AI\Core\FakeResponseGuard;
use Tests\Support\AI\Core\FakeToolRunner;
use Tests\Support\AI\Core\ScriptedModelGateway;
use Tests\Support\AI\Employees\CreatesEmployees;
use Tests\Support\FakeAiProvider;

trait CreatesHasimInboxFixtures
{
    use CreatesEmployees;

    /**
     * @return array{0: Workspace, 1: Conversation, 2: Message, 3: AiEmployee, 4: Customer}
     */
    protected function seedHasimInbox(array $employeeOverrides = []): array
    {
        $workspace = $this->workspace('Hasim Inbox');
        $employee = $this->employee($workspace, $employeeOverrides);

        $plan = Plan::query()->create([
            'code' => 'hasim_inbox_'.uniqid(),
            'name' => 'Hasim Inbox',
            'workspace_type' => 'company',
            'billing_period' => 'monthly',
            'currency' => 'USD',
            'price' => 0,
            'is_active' => true,
            'features' => ['ai', 'conversations', 'smart_replies'],
            'limits' => ['ai_usage' => 10000, 'ai_tokens' => 1000000],
        ]);

        [$customer, $conversation, $message] = app(WorkspaceContext::class)->runFor($workspace, function () use ($workspace, $plan) {
            Subscription::query()->create([
                'workspace_id' => $workspace->id,
                'plan_id' => $plan->id,
                'status' => 'active',
                'starts_at' => now(),
                'current_period_start' => now()->startOfMonth(),
                'current_period_end' => now()->endOfMonth(),
            ]);

            $customer = Customer::query()->create([
                'workspace_id' => $workspace->id,
                'name' => 'عميل تجريبي',
                'phone' => '+9665'.random_int(10000000, 99999999),
                'whatsapp' => null,
                'email' => 'hasim-inbox-'.uniqid().'@example.com',
                'orders_count' => 0,
                'total_purchases' => 0,
                'notes' => null,
                'metadata' => [],
            ]);

            $conversation = Conversation::query()->create([
                'workspace_id' => $workspace->id,
                'customer_id' => $customer->id,
                'channel' => 'web',
                'external_id' => 'hasim-'.uniqid(),
                'status' => 'open',
                'ai_enabled' => true,
                'metadata' => [],
            ]);

            $message = Message::query()->create([
                'workspace_id' => $workspace->id,
                'conversation_id' => $conversation->id,
                'customer_id' => $customer->id,
                'direction' => 'inbound',
                'message_type' => 'text',
                'content' => 'هل عندكم حذاء أسود؟',
                'ai_generated' => false,
                'metadata' => [],
            ]);

            return [$customer, $conversation, $message];
        });

        return [$workspace, $conversation, $message, $employee, $customer];
    }

    /**
     * @return array{0: ScriptedModelGateway, 1: FakeToolRunner, 2: FakeResponseGuard}
     */
    protected function bindHasimRuntime(
        ?ScriptedModelGateway $gateway = null,
        ?FakeToolRunner $tools = null,
        ?FakeResponseGuard $guard = null,
        array $modelScript = [],
    ): array {
        $gateway ??= new ScriptedModelGateway($modelScript === [] ? [new ModelResponse('أهلا، أقدر أساعدك في اختيار المنتج.')] : $modelScript);
        $tools ??= new FakeToolRunner;
        $guard ??= new FakeResponseGuard;

        $this->app->instance(HasimInboxRuntime::class, new HasimInboxRuntime(
            app(DatabaseEmployeeResolver::class),
            app(ConstitutionLoader::class),
            new InboxPolicyProvider,
            app(AiContextProvider::class),
            new LibraryTrainingProvider(
                app(TrainingContextBuilder::class),
                app(EmployeeTrainingQueryFactory::class),
            ),
            $gateway,
            $tools,
            $guard,
            app(ConversationEmployeeBinder::class),
            new HasimEvaluator,
            app(EmployeeRepository::class),
            app(AiSafety::class),
        ));

        return [$gateway, $tools, $guard];
    }

    protected function bindLegacyProvider(?FakeAiProvider $fake = null): FakeAiProvider
    {
        $fake ??= new FakeAiProvider;
        $this->app->instance(GoogleAiStudioProvider::class, $fake);
        $this->app->instance(OpenAiProvider::class, $fake);

        return $fake;
    }

    protected function boundEmployeeId(Conversation $conversation): ?int
    {
        $id = Conversation::withoutGlobalScopes()->whereKey($conversation->id)->value('ai_employee_id');

        return $id === null ? null : (int) $id;
    }

    protected function inboundFollowUp(Conversation $conversation, Customer $customer, string $content): Message
    {
        return Message::query()->create([
            'workspace_id' => $conversation->workspace_id,
            'conversation_id' => $conversation->id,
            'customer_id' => $customer->id,
            'direction' => 'inbound',
            'message_type' => 'text',
            'content' => $content,
            'ai_generated' => false,
            'metadata' => [],
        ]);
    }
}
