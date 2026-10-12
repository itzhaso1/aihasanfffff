<?php

namespace App\Http\Controllers\Api\Mobile\V1;

use App\Http\Controllers\Api\Mobile\Concerns\ResolvesMobileWorkspace;
use App\Http\Controllers\Api\Mobile\MobileController;
use App\Http\Requests\Ai\UpdateAiSettingRequest;
use App\Http\Resources\Mobile\AiSettingResource;
use App\Services\AI\AiProviderManager;
use App\Services\AI\AiReplyTextNormalizer;
use App\Services\AI\AiSafety;
use App\Services\AI\AiSettingManagementService;
use App\Services\AI\Inbox\InboxAgentPromptBuilder;
use App\Services\AI\Inbox\Persona\InboxAgentPersonaCatalog;
use App\Services\Feature\FeatureAccessService;
use App\Services\Platform\PlatformAiConfiguration;
use App\Support\Authorization\WorkspaceAccess;
use App\Support\Chat\ChatAiRole;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class AiSettingsController extends MobileController
{
    use ResolvesMobileWorkspace;

    public function __construct(
        private readonly AiSettingManagementService $aiSettings,
        private readonly WorkspaceContext $workspaceContext,
        private readonly WorkspaceAccess $workspaceAccess,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $workspace = $this->requireWorkspace($this->workspaceContext);
        $user = $request->user();
        if (! $user || ! $this->workspaceAccess->canManageAiSettings($user, $workspace)) {
            return $this->fail('لا تملك صلاحية إعدادات الذكاء الاصطناعي.', 403);
        }

        $setting = $this->aiSettings->forWorkspace($workspace);

        return $this->ok(
            new AiSettingResource($setting),
            $this->catalogMeta(),
        );
    }

    public function update(UpdateAiSettingRequest $request): JsonResponse
    {
        $workspace = $this->requireWorkspace($this->workspaceContext);
        $setting = $this->aiSettings->update($workspace, $request->validated());

        return $this->ok(
            new AiSettingResource($setting),
            $this->catalogMeta(),
            message: 'تم تحديث إعدادات الذكاء الاصطناعي.',
        );
    }

    public function probe(
        Request $request,
        AiProviderManager $providers,
        AiSafety $safety,
        FeatureAccessService $features,
        InboxAgentPromptBuilder $promptBuilder,
        AiReplyTextNormalizer $replyText,
    ): JsonResponse {
        $workspace = $this->requireWorkspace($this->workspaceContext);
        $user = $request->user();
        if (! $user || ! $this->workspaceAccess->canManageAiSettings($user, $workspace)) {
            return $this->fail('لا تملك صلاحية إعدادات الذكاء الاصطناعي.', 403);
        }

        $message = trim((string) $request->input('message', 'مرحبا، عرفني بنفسك'));
        if ($message === '' || mb_strlen($message) > 500) {
            return $this->fail('رسالة الاختبار يجب أن تكون بين 1 و 500 حرف.', 422);
        }

        $setting = $this->aiSettings->forWorkspace($workspace);
        $providerName = $safety->resolveProvider();
        $model = $safety->resolveModel();
        $instructions = trim((string) ($setting->instructions ?? ''));
        $rules = is_array($setting->rules) ? $setting->rules : [];
        $memoryOn = ! array_key_exists('memory_enabled', $rules) || (bool) $rules['memory_enabled'];
        $started = microtime(true);

        $reply = '';
        $tokens = null;
        $connected = false;
        $error = null;

        try {
            $features->assertWithinLimit($workspace, 'ai_usage', 1);
            $features->assertWithinLimit($workspace, 'ai_tokens', 128);
            $result = $providers->resolve($providerName)->generate(
                messages: [
                    ['role' => 'system', 'content' => $promptBuilder->renderStandaloneSystem($setting, firstContact: true)],
                    ['role' => 'user', 'content' => $safety->wrapUntrustedUserContent($safety->truncateInput($message, 500))],
                ],
                model: $model,
                temperature: $safety->capTemperature((float) $setting->temperature),
                maxTokens: min(256, $safety->capMaxTokens((int) $setting->max_tokens)),
            );
            $reply = $replyText->normalize(
                $safety->sanitizeReply((string) ($result['content'] ?? '')),
                AiReplyTextNormalizer::providerStoppedAtLimit($result),
            );
            $tokens = (int) ($result['tokens_used'] ?? 0);
            $connected = $reply !== '';
            $features->consumeUsage($workspace, 'ai_usage', 1, enforce: true);
            if ($tokens > 0) {
                $features->consumeUsage($workspace, 'ai_tokens', $tokens, enforce: true);
            }
        } catch (Throwable $exception) {
            $connected = false;
            $error = 'تعذر الاتصال بالمزود. تحقق من إعداد المفتاح والنموذج على الخادم.';
            report($exception);
        }

        $elapsed = (int) round((microtime(true) - $started) * 1000);
        $checks = [
            ['id' => 'provider', 'label' => 'اختبار الاتصال بالمزود', 'ok' => $connected, 'detail' => $providerName, 'elapsed_ms' => $elapsed],
            ['id' => 'message', 'label' => 'اختبار إرسال رسالة', 'ok' => $connected && $reply !== '', 'detail' => $connected ? 'تم إرسال الرسالة' : 'لم يصل رد', 'elapsed_ms' => $elapsed],
            ['id' => 'latency', 'label' => 'اختبار سرعة الاستجابة', 'ok' => $connected && $elapsed < 20000, 'detail' => $elapsed.' ms', 'elapsed_ms' => $elapsed],
            ['id' => 'model', 'label' => 'اختبار النموذج', 'ok' => $model !== '', 'detail' => $model, 'elapsed_ms' => $connected ? $elapsed : 0],
            ['id' => 'instructions', 'label' => 'اختبار التعليمات', 'ok' => $instructions !== '', 'detail' => $instructions !== '' ? 'التعليمات محفوظة' : 'لا توجد تعليمات', 'elapsed_ms' => 0],
            ['id' => 'memory', 'label' => 'اختبار الذاكرة', 'ok' => $memoryOn, 'detail' => $memoryOn ? 'الذاكرة مفعّلة' : 'الذاكرة متوقفة', 'elapsed_ms' => 0],
        ];

        return $this->ok([
            'connected' => $connected,
            'reply' => $reply,
            'model' => $model,
            'elapsed_ms' => $elapsed,
            'tokens' => $tokens,
            'error' => $error,
            'checks' => $checks,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function catalogMeta(): array
    {
        $safety = app(AiSafety::class);
        $labels = [
            'google_ai_studio' => 'Google AI Studio (Gemini)',
            'openai' => 'OpenAI',
        ];
        $providers = [];
        foreach ($safety->allowedProviders() as $id) {
            $providers[] = [
                'id' => $id,
                'label' => $labels[$id] ?? $id,
                'models' => $safety->allowedModels($id),
            ];
        }

        return [
            'ai_roles' => ChatAiRole::all(),
            'persona_catalog' => InboxAgentPersonaCatalog::schemaForApi(),
            'providers' => $providers,
            'platform_ai' => app(PlatformAiConfiguration::class)->snapshot()->toArray(),
        ];
    }
}
