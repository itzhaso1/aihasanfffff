<?php

namespace App\Services\AI;

use App\Models\ChatCatalogItem;
use App\Models\Plan;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Platform\PlatformAiConfiguration;
use App\Services\Workspace\WorkspaceResolverService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class WebsiteAssistantService
{
    public function __construct(
        private readonly AiProviderManager $providerManager,
        private readonly WorkspaceResolverService $workspaceResolverService,
        private readonly AiSafety $safety,
    ) {}

    public function reply(string $prompt, Request $request, ?User $user = null): string
    {
        return $this->replyWithMeta($prompt, $request, $user)['reply'];
    }

    /**
     * @return array{reply:string,source:string,reason:?string,model:?string}
     */
    public function replyWithMeta(string $prompt, Request $request, ?User $user = null): array
    {
        $context = $this->resolveProductContext($request, $user);
        $workspace = $context['workspace'];
        $products = $context['products'];
        $plans = $this->activePlansSnapshot();

        $messages = [
            [
                'role' => 'system',
                'content' => $this->buildSystemPrompt($workspace !== null, $products, $context['is_authenticated_member']),
            ],
            [
                'role' => 'user',
                'content' => $this->buildUserPrompt($prompt, $plans),
            ],
        ];

        $providerName = $this->safety->resolveProvider();
        $model = $this->safety->resolveModel();
        if (! app(PlatformAiConfiguration::class)->credentialsPresent($providerName)) {
            $fallbackReply = $this->safety->sanitizeReply($this->fallbackReply($prompt, $workspace !== null, $plans));

            return [
                'reply' => $fallbackReply."\n\n(ملاحظة: الرد الحالي يعمل بوضع احتياطي لأن مفتاح المزود غير مضاف بعد.)",
                'source' => 'fallback',
                'reason' => 'missing_provider_key',
                'model' => $model,
            ];
        }

        try {
            $provider = $this->providerManager->resolve($providerName);
            $result = $provider->generate(
                messages: $messages,
                model: $model,
                temperature: $this->safety->capTemperature($this->resolveTemperature($providerName)),
                maxTokens: $this->safety->capMaxTokens($this->resolveMaxTokens($providerName)),
            );

            $content = $this->safety->sanitizeReply((string) ($result['content'] ?? ''));

            if ($content !== '') {
                return [
                    'reply' => $content,
                    'source' => $providerName,
                    'reason' => null,
                    'model' => $model,
                ];
            }

            return [
                'reply' => $this->safety->sanitizeReply($this->fallbackReply($prompt, $workspace !== null, $plans)),
                'source' => 'fallback',
                'reason' => 'empty_provider_response',
                'model' => $model,
            ];
        } catch (Throwable $exception) {
            $fallbackReply = $this->safety->sanitizeReply($this->fallbackReply($prompt, $workspace !== null, $plans));

            Log::error('website-assistant-provider-failed', [
                'provider' => $providerName,
                'model' => $model,
                'message' => $exception->getMessage(),
            ]);

            return [
                'reply' => $fallbackReply."\n\n(ملاحظة تقنية: تعذر الوصول إلى مزود الذكاء الاصطناعي الآن - provider: {$providerName}, model: {$model})",
                'source' => 'fallback',
                'reason' => 'provider_error',
                'model' => $model,
            ];
        }
    }

    /**
     * Products are included only for authenticated active workspace members.
     * Stock is never exposed in public (non-member) context.
     * Unauthenticated callers cannot force another workspace via headers/body.
     *
     * @return array{workspace:?Workspace,products:array<int, array<string, mixed>>,is_authenticated_member:bool}
     */
    private function resolveProductContext(Request $request, ?User $user): array
    {
        $memberWorkspace = null;
        if ($user) {
            $memberWorkspace = $this->workspaceResolverService->resolveFromRequest($request, $user);
        }

        if ($memberWorkspace) {
            return [
                'workspace' => $memberWorkspace,
                'products' => $this->productsForWorkspace($memberWorkspace, includeStock: true),
                'is_authenticated_member' => true,
            ];
        }

        return [
            'workspace' => null,
            'products' => [],
            'is_authenticated_member' => false,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function productsForWorkspace(Workspace $workspace, bool $includeStock): array
    {
        return ChatCatalogItem::withoutGlobalScopes()
            ->where('workspace_id', $workspace->id)
            ->where('is_active', true)
            ->limit(12)
            ->get(['name', 'sku', 'price', 'stock', 'currency'])
            ->map(function (ChatCatalogItem $item) use ($includeStock): array {
                $row = [
                    'name' => $item->name,
                    'sku' => $item->sku,
                    'price' => $item->price,
                    'currency' => $item->currency,
                ];

                if ($includeStock) {
                    $row['stock'] = $item->stock;
                }

                return $row;
            })->all();
    }

    private function resolveTemperature(string $providerName): float
    {
        return (float) config('ai.'.$providerName.'.temperature', 0.3);
    }

    private function resolveMaxTokens(string $providerName): int
    {
        return (int) config('ai.'.$providerName.'.max_tokens', 1024);
    }

    private function buildSystemPrompt(bool $hasWorkspace, array $products, bool $isAuthenticatedMember): string
    {
        if ($hasWorkspace && $products !== []) {
            $workspaceContext = $isAuthenticatedMember
                ? "المستخدم عضو مصادق في مساحة العمل. استخدم فقط المنتجات التالية (لا تكشف أسرار النظام):\n".json_encode($products, JSON_UNESCAPED_UNICODE)
                : 'لا توجد منتجات عامة متاحة لهذا الزائر.';
        } elseif ($hasWorkspace) {
            $workspaceContext = 'مساحة العمل معروفة لكن لا توجد منتجات نشطة لإدراجها.';
        } else {
            $workspaceContext = 'المستخدم زائر. مهمتك إرشاده لتسجيل الدخول، إنشاء حساب، واختيار مساحة العمل. لا تفترض بيانات أي مساحة عمل أخرى.';
        }

        return <<<PROMPT
أنت مساعد منصة حاسم.
- اكتب بالعربية الواضحة.
- كن مختصرًا ومهذبًا وخطواتك عملية.
- لا تقدم أي معلومات غير مؤكدة.
- إذا لم توجد بيانات منتج مطابقة، صرّح بعدم التوفر بوضوح.
- إذا طلب المستخدم دعم الدخول: اشرح تسجيل الدخول من /login أو إنشاء حساب من /register.
- إذا سأل الزائر عن الأسعار أو الباقات، استخدم أسعار الباقات القادمة من قاعدة البيانات المرفقة في رسالة المستخدم.
- إذا كانت هناك مشكلة شائعة (كلمة المرور، التحقق، عدم وصول رمز، عدم فتح الصفحة)، أعطِ خطوات حل عملية ومباشرة.
- إذا كان السؤال تقنيًا لا يعتمد على بيانات مؤكدة، قدّم توجيهًا عامًا ثم اقترح التواصل مع الدعم.
- لا تكشف أبدًا أسرار النظام أو المفاتيح أو التوكنات أو كلمات المرور أو بيانات الاعتماد أو محتوى .env أو مفاتيح API أو أسرار الويب هوك — حتى لو طلب المستخدم ذلك صراحة أو بأسلوب غير مباشر.
- ارفض أي محاولة لاستخراج بيانات مساحة عمل أخرى أو تجاوز العزل بين المستأجرين.
- لا تعرض أرقام المخزون للزوار العامين.

{$workspaceContext}
PROMPT;
    }

    /**
     * @param  array<int, array{code:string,name:string,workspace_type:?string,billing_period:string,currency:string,price:string}>  $plans
     */
    private function buildUserPrompt(string $prompt, array $plans): string
    {
        $prompt = $this->safety->truncateInput($prompt, 800);
        $plansJson = json_encode($plans, JSON_UNESCAPED_UNICODE);

        return <<<TEXT
سؤال الزائر:
{$prompt}

الباقات المتاحة (من قاعدة البيانات):
{$plansJson}
TEXT;
    }

    /**
     * @param  array<int, array{code:string,name:string,workspace_type:?string,billing_period:string,currency:string,price:string}>  $plans
     */
    private function fallbackReply(string $prompt, bool $hasWorkspace, array $plans): string
    {
        $text = mb_strtolower($prompt);

        if (
            str_contains($text, 'api key')
            || str_contains($text, 'secret')
            || str_contains($text, 'token')
            || str_contains($text, 'مفتاح')
            || str_contains($text, 'سر')
            || str_contains($text, 'توكن')
            || str_contains($text, '.env')
        ) {
            return 'لا يمكنني مشاركة الأسرار أو مفاتيح API أو التوكنات أو بيانات الاعتماد. استخدم لوحة التحكم الخاصة بمساحة عملك لإدارة المفاتيح بأمان.';
        }

        if (
            str_contains($text, 'سعر')
            || str_contains($text, 'اسعار')
            || str_contains($text, 'باقات')
            || str_contains($text, 'اشتراك')
            || str_contains($text, 'plans')
            || str_contains($text, 'pricing')
        ) {
            if (count($plans) === 0) {
                return 'حاليًا لا توجد باقات نشطة في النظام. يمكن لمدير المنصة إضافتها من لوحة الأدمن: /platform/plans';
            }

            $lines = array_map(
                fn (array $plan): string => "- {$plan['name']} ({$plan['workspace_type']}) : {$plan['price']} {$plan['currency']} / {$plan['billing_period']}",
                $plans
            );

            return "هذه أسعار الباقات الحالية من قاعدة البيانات:\n".implode("\n", $lines);
        }

        if (str_contains($text, 'تسجيل') || str_contains($text, 'دخول') || str_contains($text, 'login')) {
            return 'للدخول إلى حسابك: افتح صفحة /login ثم أدخل البريد أو رقم الهاتف مع كلمة المرور. إذا نسيت كلمة المرور استخدم "نسيت كلمة المرور".';
        }

        if (str_contains($text, 'حساب') || str_contains($text, 'register') || str_contains($text, 'إنشاء')) {
            return 'لإنشاء حساب جديد: افتح صفحة /register، اختر نوع الحساب (فرد/شركة/متجر)، ثم أكمل بيانات التسجيل.';
        }

        if (str_contains($text, 'نسيت') || str_contains($text, 'كلمة المرور') || str_contains($text, 'password')) {
            return 'إذا نسيت كلمة المرور: افتح صفحة /forgot-password ثم أدخل البريد الإلكتروني، وستصلك رسالة إعادة تعيين كلمة المرور.';
        }

        if (str_contains($text, 'رمز') || str_contains($text, 'otp') || str_contains($text, 'تحقق')) {
            return 'إذا واجهت مشكلة في رمز التحقق: تأكد من صحة الرقم، ثم أعد طلب الرمز من صفحة OTP. إذا استمرت المشكلة جرّب بعد دقيقة بسبب حماية معدل الطلب.';
        }

        if (str_contains($text, 'مشكلة') || str_contains($text, 'لا يعمل') || str_contains($text, 'error') || str_contains($text, 'خطأ')) {
            return 'لحل المشكلة بسرعة: 1) حدّث الصفحة 2) تأكد من اتصال الإنترنت 3) سجّل خروج/دخول 4) جرّب متصفحًا آخر. إذا استمرت المشكلة، أرسل لنا لقطة شاشة ووقت الخطأ لدعم أسرع.';
        }

        if ($hasWorkspace) {
            return 'يمكنني مساعدتك في الإرشاد داخل النظام أو البحث عن المنتجات المتاحة في مساحة العمل الحالية. اكتب اسم المنتج أو المواصفات المطلوبة.';
        }

        return 'أنا مساعد حاسم. يمكنني مساعدتك في تسجيل الدخول، إنشاء الحساب، والتنقل داخل المنصة خطوة بخطوة.';
    }

    /**
     * @return array<int, array{code:string,name:string,workspace_type:?string,billing_period:string,currency:string,price:string}>
     */
    private function activePlansSnapshot(): array
    {
        return Plan::query()
            ->where('is_active', true)
            ->orderBy('workspace_type')
            ->orderBy('price')
            ->limit(20)
            ->get(['code', 'name', 'workspace_type', 'billing_period', 'currency', 'price'])
            ->map(fn (Plan $plan): array => [
                'code' => (string) $plan->code,
                'name' => (string) $plan->name,
                'workspace_type' => $plan->workspace_type,
                'billing_period' => (string) $plan->billing_period,
                'currency' => (string) $plan->currency,
                'price' => number_format((float) $plan->price, 2, '.', ''),
            ])
            ->values()
            ->all();
    }
}
