<?php

declare(strict_types=1);

namespace Tests\Unit\AI\Models;

use App\AI\Core\AiRunMode;
use App\AI\Core\Data\ModelRequest;
use App\AI\Core\Data\ModelResponse;
use App\AI\Core\Data\ToolSpec;
use App\AI\Core\Prompt\PromptSection;
use App\AI\Core\PromptComposer;
use App\AI\Models\HasimModelGateway;
use App\AI\Models\ModelGatewayException;
use App\AI\Models\ModelSettingsResolver;
use App\AI\Models\ProviderCapability;
use App\AI\Models\ProviderId;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\AI\Employees\CreatesEmployees;
use Tests\Support\AI\Models\FakeProviderAdapter;
use Tests\TestCase;

class HasimModelGatewayTest extends TestCase
{
    use CreatesEmployees;
    use RefreshDatabase;

    public function test_gateway_routes_to_the_platform_provider_adapter(): void
    {
        $this->setPlatformAi('openai', 'gpt-4o-mini');
        $workspace = $this->workspace();
        $employee = $this->employee($workspace, [
            'model' => ['temperature' => 0.2, 'response_length' => 'short'],
        ]);
        $openai = new FakeProviderAdapter(ProviderId::OPENAI, response: new ModelResponse('openai-ok'));
        $gemini = new FakeProviderAdapter(ProviderId::GEMINI, response: new ModelResponse('gemini-ok'));

        $response = (new HasimModelGateway(app(ModelSettingsResolver::class), [$openai, $gemini]))
            ->generate($this->request((int) $employee->id));

        $this->assertSame('openai-ok', $response->text);
        $this->assertSame((int) $employee->id, $openai->settings?->employeeId);
        $this->assertNull($gemini->request);
    }

    public function test_tools_are_stripped_when_the_adapter_cannot_call_them(): void
    {
        $this->setPlatformAi('openai', 'gpt-4o-mini');
        $workspace = $this->workspace();
        $employee = $this->employee($workspace);
        $openai = new FakeProviderAdapter(ProviderId::OPENAI, new ProviderCapability(nativeTools: false));

        (new HasimModelGateway(app(ModelSettingsResolver::class), [$openai]))
            ->generate(new ModelRequest(
                employeeId: (int) $employee->id,
                runMode: AiRunMode::Preview,
                prompt: (new PromptComposer)->compose([], [], [], [], [], PromptSection::userMessage('هلا')),
                tools: [new ToolSpec('search_products', 'Search')],
            ));

        $this->assertSame([], $openai->request?->tools);
        $this->assertSame([], $openai->request?->toolExchanges);
    }

    public function test_gemini_adapter_is_used_when_platform_selects_google_ai_studio(): void
    {
        $this->setPlatformAi('google_ai_studio', 'gemini-2.5-flash');
        $workspace = $this->workspace();
        $employee = $this->employee($workspace);
        config(['services.google_ai_studio.key' => 'test-gemini-key']);
        Http::fake(['*' => Http::response([
            'candidates' => [[
                'finishReason' => 'STOP',
                'content' => ['parts' => [['text' => 'هلا والله']]],
            ]],
            'usageMetadata' => ['promptTokenCount' => 3, 'candidatesTokenCount' => 2],
        ])]);

        $response = (new HasimModelGateway(app(ModelSettingsResolver::class)))
            ->generate($this->request((int) $employee->id));

        $this->assertSame('هلا والله', $response->text);
        $this->assertSame(ProviderId::GEMINI, $response->usage?->provider);
        Http::assertSent(fn ($request): bool => str_contains($request->url(), 'gemini-2.5-flash:generateContent'));
    }

    public function test_unknown_employee_fails_before_any_provider_call(): void
    {
        Http::fake();

        try {
            (new HasimModelGateway(app(ModelSettingsResolver::class)))->generate($this->request(999999));
            $this->fail('Missing employee was accepted.');
        } catch (ModelGatewayException $exception) {
            $this->assertSame(ModelGatewayException::SETTINGS_NOT_FOUND, $exception->errorCode);
        }

        Http::assertNothingSent();
    }

    private function request(int $employeeId): ModelRequest
    {
        return new ModelRequest(
            $employeeId,
            AiRunMode::Preview,
            (new PromptComposer)->compose([], [], [], [], [], PromptSection::userMessage('هلا')),
        );
    }
}
