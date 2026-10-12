<?php

namespace App\Services\Conversation;

use App\Enums\ConversationClassificationSource;
use App\Enums\ConversationCustomerPriority;
use App\Enums\ConversationPaymentStatus;
use App\Enums\ConversationReviewStatus;
use App\Models\Conversation;
use App\Models\User;
use App\Support\Chat\ArabicSearchNormalizer;
use Illuminate\Validation\ValidationException;

/**
 * Conversation metadata only. Never authorizes tools, payment, or digital inventory.
 */
class ConversationClassificationService
{
    public function __construct(private readonly ArabicSearchNormalizer $normalizer) {}

    public function applySignals(Conversation $conversation, string $inboundText): void
    {
        if ($conversation->classification_source === ConversationClassificationSource::Manual->value) {
            return;
        }

        $text = $this->normalizer->normalize($inboundText);
        $changes = [];
        $reasons = [];

        if ($this->containsAny($text, ['شكوى', 'تصعيد', 'كلم المدير', 'ما انحلت'])) {
            $changes['customer_priority'] = ConversationCustomerPriority::Important->value;
            $changes['review_status'] = ConversationReviewStatus::NeedsReview->value;
            $reasons[] = 'تصعيد أو شكوى';
        } elseif ($this->containsAny($text, ['عاجل', 'ما وصل', 'مشكله', 'مشكلة'])) {
            $changes['review_status'] = ConversationReviewStatus::NeedsReview->value;
            $reasons[] = 'طلب غير محسوم';
        }

        if ($this->containsAny($text, ['مدفوع', 'تم الدفع', 'حولت المبلغ'])) {
            $changes['payment_status'] = ConversationPaymentStatus::Paid->value;
            $reasons[] = 'إشارة دفع صريحة';
        }

        if ($changes === []) {
            return;
        }

        $conversation->forceFill([
            ...$changes,
            'classification_source' => ConversationClassificationSource::System->value,
            'classification_updated_by' => null,
            'classification_updated_at' => now(),
            'classification_reason' => implode('؛ ', $reasons),
        ])->save();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function applyManual(Conversation $conversation, User $actor, array $payload): Conversation
    {
        $conversation->forceFill([
            ...$this->validated($payload),
            'classification_source' => ConversationClassificationSource::Manual->value,
            'classification_updated_by' => $actor->id,
            'classification_updated_at' => now(),
            'classification_reason' => 'تعديل يدوي من الموظف',
        ])->save();

        return $conversation->refresh();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function applyAiSuggestion(Conversation $conversation, array $payload): Conversation
    {
        if ($conversation->classification_source === ConversationClassificationSource::Manual->value) {
            throw ValidationException::withMessages([
                'classification' => 'التصنيف اليدوي لا يُستبدل تلقائيًا.',
            ]);
        }

        $conversation->forceFill([
            ...$this->validated($payload),
            'classification_source' => ConversationClassificationSource::Ai->value,
            'classification_updated_by' => null,
            'classification_updated_at' => now(),
            'classification_reason' => $this->reason($payload['reason'] ?? null),
        ])->save();

        return $conversation->refresh();
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, string>
     */
    public function validated(array $payload): array
    {
        $priority = $this->enum($payload['customer_priority'] ?? null, ConversationCustomerPriority::class, 'customer_priority');
        $payment = $this->enum($payload['payment_status'] ?? null, ConversationPaymentStatus::class, 'payment_status');
        $review = $this->enum($payload['review_status'] ?? null, ConversationReviewStatus::class, 'review_status');

        return [
            'customer_priority' => $priority,
            'payment_status' => $payment,
            'review_status' => $review,
        ];
    }

    /**
     * @param  class-string  $enum
     */
    private function enum(mixed $value, string $enum, string $field): string
    {
        if (! is_string($value) || $enum::tryFrom($value) === null) {
            throw ValidationException::withMessages([
                $field => 'قيمة التصنيف غير مسموحة.',
            ]);
        }

        return $value;
    }

    private function reason(mixed $reason): string
    {
        $text = is_string($reason) ? trim($reason) : '';
        if ($text === '') {
            return 'اقتراح منظم';
        }

        return mb_substr($text, 0, 240);
    }

    /**
     * @param  list<string>  $needles
     */
    private function containsAny(string $text, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($text, $this->normalizer->normalize($needle))) {
                return true;
            }
        }

        return false;
    }
}
