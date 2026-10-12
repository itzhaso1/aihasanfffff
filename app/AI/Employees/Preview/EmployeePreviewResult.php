<?php

declare(strict_types=1);

namespace App\AI\Employees\Preview;

use App\AI\Core\AiFailureCode;
use App\AI\Core\AiResponse;
use App\AI\Core\AiResponseStatus;
use App\AI\Core\Decision\AiFailure;
use App\AI\Models\ResolvedModelSettings;

/**
 * Preview/test payload for the employee settings UI. Latency over the warning
 * threshold is a performance note, not a hard failure, when a valid reply exists.
 */
final readonly class EmployeePreviewResult
{
    public const LATENCY_WARNING_MS = 20_000;

    public const OUTCOME_SUCCESS = 'success';

    public const OUTCOME_LATENCY_WARNING = 'latency_warning';

    public const OUTCOME_FAILED = 'failed';

    public function __construct(
        public AiResponse $response,
        public ResolvedModelSettings $settings,
        public string $settingsSource,
        public int $elapsedMs,
        public string $outcome,
        public ?string $failureReason,
    ) {}

    public static function from(
        AiResponse $response,
        ResolvedModelSettings $settings,
        string $settingsSource,
        int $elapsedMs,
        int $latencyWarningMs = self::LATENCY_WARNING_MS,
    ): self {
        $text = trim((string) $response->text);
        $succeeded = $response->status === AiResponseStatus::Replied && $text !== '';
        $outcome = self::classify($succeeded, $elapsedMs, $latencyWarningMs);

        return new self(
            response: $response,
            settings: $settings,
            settingsSource: $settingsSource,
            elapsedMs: max(0, $elapsedMs),
            outcome: $outcome,
            failureReason: $succeeded ? null : self::reason($response->decision->failure),
        );
    }

    public static function classify(bool $succeeded, int $elapsedMs, int $latencyWarningMs = self::LATENCY_WARNING_MS): string
    {
        if (! $succeeded) {
            return self::OUTCOME_FAILED;
        }

        return $elapsedMs >= $latencyWarningMs
            ? self::OUTCOME_LATENCY_WARNING
            : self::OUTCOME_SUCCESS;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $succeeded = $this->outcome !== self::OUTCOME_FAILED;
        $slow = $this->outcome === self::OUTCOME_LATENCY_WARNING;
        $usage = $this->response->decision->modelUsage;
        $provider = $this->settings->provider;
        $model = $this->settings->model;
        $usedModel = $model;

        return [
            'status' => $this->response->status->value,
            'outcome' => $this->outcome,
            'text' => $this->response->text,
            'deliverable' => false,
            'employee_id' => $this->settings->employeeId,
            'provider' => $provider,
            'model' => $model,
            'used_model' => $usedModel,
            'settings_source' => $this->settingsSource,
            'elapsed_ms' => $this->elapsedMs,
            'tokens' => $usage?->totalTokens(),
            'error' => $this->failureReason,
            'failure' => $this->response->decision->failure?->toArray(),
            'checks' => [
                [
                    'id' => 'provider',
                    'label' => 'اختبار الاتصال بالمزود',
                    'ok' => $succeeded,
                    'warning' => false,
                    'detail' => $succeeded ? 'تم الاتصال' : 'تعذر الاتصال',
                    'elapsed_ms' => $this->elapsedMs,
                ],
                [
                    'id' => 'message',
                    'label' => 'اختبار إرسال رسالة',
                    'ok' => $succeeded,
                    'warning' => false,
                    'detail' => $succeeded ? 'تم إنشاء رد المعاينة' : 'لم يصل رد',
                    'elapsed_ms' => $this->elapsedMs,
                ],
                [
                    'id' => 'latency',
                    'label' => 'اختبار سرعة الاستجابة',
                    'ok' => $succeeded,
                    'warning' => $slow,
                    'detail' => $this->elapsedMs.' ms',
                    'elapsed_ms' => $this->elapsedMs,
                ],
                [
                    'id' => 'model',
                    'label' => 'النموذج المستخدم',
                    'ok' => $model !== '',
                    'warning' => false,
                    'detail' => $model,
                    'elapsed_ms' => $succeeded ? $this->elapsedMs : 0,
                ],
            ],
            'decision' => $this->response->decision->toArray(),
        ];
    }

    private static function reason(?AiFailure $failure): ?string
    {
        if ($failure === null) {
            return 'فشلت المعاينة.';
        }

        return match ($failure->code) {
            AiFailureCode::EmptyModelResponse => 'المزود لم يُرجع رداً صالحاً.',
            AiFailureCode::ModelError => 'تعذر استدعاء النموذج. تحقق من إعداد المفتاح أو مهلة المزود.',
            AiFailureCode::ModelSafetyStop => 'أوقف المزود الرد بسبب سياسة السلامة.',
            AiFailureCode::InvalidPrompt => 'تعذر تجهيز طلب المعاينة.',
            AiFailureCode::DependencyFailed => 'تعذر تجهيز معاينة الموظف.',
            default => 'فشلت المعاينة.',
        };
    }
}
