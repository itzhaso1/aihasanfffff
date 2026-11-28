<?php

declare(strict_types=1);

namespace App\Services\AI\Inbox\Memory;

use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Message;
use Illuminate\Support\Facades\Cache;

final class ConversationFactsMemory
{
    public const TTL_SECONDS = 86400; // 24 hours

    public static function key(int $workspaceId, int $conversationId): string
    {
        return "conversation_facts:{$workspaceId}:{$conversationId}";
    }

    public static function lockKey(int $workspaceId, int $conversationId): string
    {
        return "conversation_facts_lock:{$workspaceId}:{$conversationId}";
    }

    /**
     * Default empty structure for conversation facts.
     *
     * @return array<string, mixed>
     */
    public static function defaults(): array
    {
        return [
            'customer_name' => null,
            'interested_product_id' => null,
            'interested_product_name' => null,
            'selected_variant' => null,
            'quantity' => null,
            'budget' => null,
            'purchase_intent' => false,
            'phone_number' => null,
            'address' => null,
            'city' => null,
            'order_number' => null,
            'price_clarified' => false,
            'delivery_clarified' => false,
            'answered_questions' => [],
            'recent_ai_openings' => [],
            'last_updated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Retrieve facts from Cache; fallback to database metadata if not cached.
     *
     * @return array<string, mixed>
     */
    public function get(int $workspaceId, int $conversationId): array
    {
        $cached = Cache::get(self::key($workspaceId, $conversationId));
        if (is_array($cached) && ! empty($cached)) {
            return $cached;
        }

        $conversation = Conversation::withoutGlobalScopes()
            ->where('workspace_id', $workspaceId)
            ->whereKey($conversationId)
            ->first();

        if ($conversation === null) {
            return self::defaults();
        }

        $metadata = is_array($conversation->metadata) ? $conversation->metadata : [];
        $stored = $metadata['conversation_facts'] ?? null;

        $facts = is_array($stored)
            ? array_merge(self::defaults(), $stored)
            : self::defaults();

        // If customer exists on conversation, sync customer name and phone
        if ($conversation->customer_id !== null) {
            $customer = Customer::withoutGlobalScopes()
                ->where('workspace_id', $workspaceId)
                ->whereKey($conversation->customer_id)
                ->first();

            if ($customer !== null) {
                if ($facts['customer_name'] === null && ! empty($customer->name)) {
                    $facts['customer_name'] = (string) $customer->name;
                }
                if ($facts['phone_number'] === null && ! empty($customer->phone)) {
                    $facts['phone_number'] = (string) $customer->phone;
                }
            }
        }

        Cache::put(self::key($workspaceId, $conversationId), $facts, self::TTL_SECONDS);

        return $facts;
    }

    /**
     * Process an incoming or outgoing message and update facts memory.
     *
     * @return array<string, mixed>
     */
    public function observeMessage(Message $message): array
    {
        if ($message->workspace_id === null || $message->conversation_id === null) {
            return self::defaults();
        }

        $workspaceId = (int) $message->workspace_id;
        $conversationId = (int) $message->conversation_id;

        $lock = Cache::lock(self::lockKey($workspaceId, $conversationId), 10);

        return $lock->get(function () use ($workspaceId, $conversationId, $message): array {
            $currentFacts = $this->get($workspaceId, $conversationId);
            $extracted = $this->extractFactsFromText(
                (string) ($message->content ?? ''),
                (string) ($message->direction ?? 'inbound'),
                $currentFacts,
            );

            // If message references product from metadata or state
            $msgMeta = is_array($message->metadata) ? $message->metadata : [];
            if (! empty($msgMeta['product_id']) && $extracted['interested_product_id'] === null) {
                $extracted['interested_product_id'] = (int) $msgMeta['product_id'];
            }
            if (! empty($msgMeta['product_name']) && $extracted['interested_product_name'] === null) {
                $extracted['interested_product_name'] = (string) $msgMeta['product_name'];
            }

            $merged = $this->mergeFacts($currentFacts, $extracted);
            $this->persistFacts($workspaceId, $conversationId, $merged);

            return $merged;
        }) ?? $this->get($workspaceId, $conversationId);
    }

    /**
     * Observe an outbound AI/staff reply and mark clarifications (price, delivery).
     *
     * @return array<string, mixed>
     */
    public function observeOutboundReply(int $workspaceId, int $conversationId, string $reply): array
    {
        $lock = Cache::lock(self::lockKey($workspaceId, $conversationId), 10);

        return $lock->get(function () use ($workspaceId, $conversationId, $reply): array {
            $currentFacts = $this->get($workspaceId, $conversationId);
            $extracted = $this->extractFactsFromText($reply, 'outbound', $currentFacts);

            $merged = $this->mergeFacts($currentFacts, $extracted);
            $this->persistFacts($workspaceId, $conversationId, $merged);

            return $merged;
        }) ?? $this->get($workspaceId, $conversationId);
    }

    /**
     * Mark price as clarified for an active product.
     *
     * @return array<string, mixed>
     */
    public function recordPriceStated(
        int $workspaceId,
        int $conversationId,
        ?int $productId = null,
        ?string $productName = null,
    ): array {
        $facts = $this->get($workspaceId, $conversationId);
        $facts['price_clarified'] = true;
        if ($productId !== null) {
            $facts['interested_product_id'] = $productId;
        }
        if ($productName !== null) {
            $facts['interested_product_name'] = $productName;
        }

        return $this->persistFacts($workspaceId, $conversationId, $facts);
    }

    /**
     * Mark delivery details as clarified.
     *
     * @return array<string, mixed>
     */
    public function recordDeliveryClarified(int $workspaceId, int $conversationId): array
    {
        $facts = $this->get($workspaceId, $conversationId);
        $facts['delivery_clarified'] = true;

        return $this->persistFacts($workspaceId, $conversationId, $facts);
    }

    /**
     * Invalidate facts cache for this conversation.
     */
    public function invalidate(int $workspaceId, int $conversationId): void
    {
        Cache::forget(self::key($workspaceId, $conversationId));
    }

    /**
     * Synchronize verified order/address mutations immediately in both cache and DB metadata.
     *
     * @param  array<string, mixed>  $extraFacts
     * @return array<string, mixed>
     */
    public function syncOrderState(
        int $workspaceId,
        int $conversationId,
        ?string $orderNumber = null,
        ?string $address = null,
        array $extraFacts = [],
    ): array {
        $lock = Cache::lock(self::lockKey($workspaceId, $conversationId), 10);

        return $lock->get(function () use ($workspaceId, $conversationId, $orderNumber, $address, $extraFacts): array {
            $facts = $this->get($workspaceId, $conversationId);
            if ($orderNumber !== null && trim($orderNumber) !== '') {
                $facts['order_number'] = trim($orderNumber);
            }
            if ($address !== null) {
                $clean = trim($address);
                $facts['address'] = $clean !== '' ? $clean : null;
            }
            foreach ($extraFacts as $key => $val) {
                if (is_string($key) && $key !== '') {
                    $facts[$key] = $val;
                }
            }
            $facts['last_updated_at'] = now()->toIso8601String();

            return $this->persistFacts($workspaceId, $conversationId, $facts);
        }) ?? $this->get($workspaceId, $conversationId);
    }

    /**
     * Extract structured facts from text using Arabic/Gulf patterns.
     *
     * @param  array<string, mixed>  $existingFacts
     * @return array<string, mixed>
     */
    public function extractFactsFromText(string $text, string $direction, array $existingFacts = []): array
    {
        $extracted = [];
        $trimmed = trim($text);

        if ($trimmed === '') {
            return $extracted;
        }

        if ($direction === 'inbound') {
            // 1. Phone number
            if (preg_match('/(?:(?:\+|00)966|0)?5[0-9]{8}/', $trimmed, $phoneMatch)) {
                $extracted['phone_number'] = $phoneMatch[0];
            } elseif (preg_match('/(?:\+|00)?[0-9]{9,15}/', $trimmed, $phoneMatch)) {
                $extracted['phone_number'] = $phoneMatch[0];
            }

            // 2. Purchase intent ("أبغاه", "ابغاه", "ابيه", "باخذه", "احجزه", "اعتمد", "شريت")
            if (preg_match('/(?:أبغاه|ابغاه|أبيه|ابيه|باخذه|باخذها|شريت|خلاص باخذه|خلاص ابغاه|احجزه|احجزلي|اعتمد|أريده|اريده|بشتريه)/u', $trimmed)) {
                $extracted['purchase_intent'] = true;
            }

            // 3. City / Delivery location
            $cities = [
                'الرياض', 'جدة', 'مكة', 'المدينة', 'الدمام', 'الخبر', 'الظهران',
                'الطائف', 'تبوك', 'بريدة', 'عنيزة', 'أبها', 'خميس مشيط', 'حائل',
                'نجران', 'جازان', 'الهفوف', 'الجبيل', 'ينبع', 'حفر الباطن',
            ];
            foreach ($cities as $city) {
                if (mb_strpos($trimmed, $city) !== false) {
                    $extracted['city'] = $city;
                    break;
                }
            }

            if (preg_match('/(?:عنواني|العنوان|التوصيل إلى|التوصيل لـ|في حي|حي)\s*[:：]?\s*([^\n.,!]+)/u', $trimmed, $addrMatch)) {
                $extracted['address'] = trim($addrMatch[1]);
            }

            // 4. Quantity
            if (preg_match('/(?:حبتين|قطعتين|نسختين)/u', $trimmed)) {
                $extracted['quantity'] = 2;
            } elseif (preg_match('/(?:الكمية|كمية|عدد|أبي|ابغى|احتاج)\s*[:：]?\s*(\d+)\s*(?:حبة|حبات|قطع|قطعة|نسخة|نسخ)?/u', $trimmed, $qtyMatch)) {
                $qty = (int) $qtyMatch[1];
                if ($qty > 0 && $qty < 1000) {
                    $extracted['quantity'] = $qty;
                }
            } elseif (preg_match('/(?:^|[^\d])(\d+)\s*(?:حبة|حبات|قطع|قطعة|نسخة|نسخ)\b/u', $trimmed, $qtyMatch)) {
                $qty = (int) $qtyMatch[1];
                if ($qty > 0 && $qty < 1000) {
                    $extracted['quantity'] = $qty;
                }
            }

            // 5. Customer name
            if (preg_match('/(اسمي|أنا|معاك|معك)\s+([^\s\d.,!؟]+(?:\s+[^\s\d.,!؟]+)?)/u', $trimmed, $nameMatch)) {
                $introKeyword = trim($nameMatch[1]);
                $words = preg_split('/\s+/u', trim($nameMatch[2])) ?: [];
                $firstWord = $words[0] ?? '';
                $secondWord = $words[1] ?? '';
                $nonNameWords = [
                    'ابغى', 'أبغى', 'ابي', 'أبي', 'عندي', 'حاب', 'حابة', 'في', 'من', 'على',
                    'عند', 'مع', 'مش', 'مو', 'بدي', 'عايز', 'عاوز', 'محتاج', 'احجز', 'بطلب',
                    'هنا', 'مستعجل', 'زعلان', 'موجود', 'جديد', 'بغيت', 'اريد', 'أريد',
                ];
                $secondIsStop = $secondWord === ''
                    || str_starts_with($secondWord, 'و')
                    || in_array($secondWord, $nonNameWords, true);
                $candName = $secondIsStop ? $firstWord : $firstWord.' '.$secondWord;
                $alreadyNamed = ! empty($existingFacts['customer_name']) && $introKeyword !== 'اسمي';
                if ($candName !== '' && ! $alreadyNamed && ! in_array($firstWord, $nonNameWords, true)) {
                    $extracted['customer_name'] = $candName;
                }
            }

            // 6. Budget ("ميزانيتي 200", "تحت 150", "أقل من 300 ريال", "بحدود 250")
            if (preg_match('/(?:ميزانيت[ي|ك]|بحدود|اقل من|أقل من|تحت|ما يتجاوز)\s*[:：]?\s*([0-9٠-٩۰-۹]+)/u', $trimmed, $budgetMatch)) {
                $budgetDigits = strtr($budgetMatch[1], [
                    '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
                    '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
                    '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
                    '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
                ]);
                if (is_numeric($budgetDigits) && (int) $budgetDigits > 0) {
                    $extracted['budget'] = (int) $budgetDigits;
                }
            }

            // 7. Variant / color / size hint
            if (preg_match('/\b(XS|S|M|L|XL|XXL)\b/i', $trimmed, $sizeMatch)) {
                $extracted['selected_variant'] = strtoupper($sizeMatch[1]);
            } elseif (preg_match('/(أسود|اسود|أبيض|ابيض|أزرق|ازرق|أحمر|احمر|أخضر|اخضر|رمادي|بيج|بني|وردي|كحلي|ذهبي|فضي|مقاس\s+(?:صغير|وسط|كبير))/u', $trimmed, $variantHint)) {
                $extracted['selected_variant'] = trim($variantHint[1]);
            }
        } elseif ($direction === 'outbound') {
            // Outbound message checks:
            // 1. Price clarified
            if (preg_match('/(?:\b\d+(?:\.\d+)?\s*(?:ريال|SAR|ر\.س|درهم|دينار)|سعر(?:ه|ها)?\s*هو?\s*\d+)/u', $trimmed)) {
                $extracted['price_clarified'] = true;
            }

            // 2. Delivery clarified
            if (preg_match('/(?:التوصيل\s*(?:متاح|مجاني|خلال|سريع)|الشحن\s*(?:متاح|مجاني)|نوصل\s*إلى)/u', $trimmed)) {
                $extracted['delivery_clarified'] = true;
            }

            // 3. Track recent AI openings (first 36 chars of reply) to deter repetitive phrasing
            $firstLine = trim(strtok($trimmed, "\n") ?: $trimmed);
            $opening = mb_substr($firstLine, 0, 36);
            if ($opening !== '') {
                $openings = is_array($existingFacts['recent_ai_openings'] ?? null)
                    ? $existingFacts['recent_ai_openings']
                    : [];
                $openings[] = $opening;
                $extracted['recent_ai_openings'] = array_values(array_slice($openings, -4));
            }
        }

        return $extracted;
    }

    /**
     * Merge new extracted facts with existing facts.
     *
     * @param  array<string, mixed>  $current
     * @param  array<string, mixed>  $new
     * @return array<string, mixed>
     */
    private function mergeFacts(array $current, array $new): array
    {
        $merged = $current;

        foreach ($new as $key => $val) {
            if ($val !== null && $val !== '') {
                $merged[$key] = $val;
            }
        }

        $merged['last_updated_at'] = now()->toIso8601String();

        return $merged;
    }

    /**
     * Persist facts both to Cache and Database ($conversation->metadata['conversation_facts']).
     *
     * @param  array<string, mixed>  $facts
     * @return array<string, mixed>
     */
    private function persistFacts(int $workspaceId, int $conversationId, array $facts): array
    {
        Cache::put(self::key($workspaceId, $conversationId), $facts, self::TTL_SECONDS);

        $conversation = Conversation::withoutGlobalScopes()
            ->where('workspace_id', $workspaceId)
            ->whereKey($conversationId)
            ->first();

        if ($conversation !== null) {
            $metadata = is_array($conversation->metadata) ? $conversation->metadata : [];
            $metadata['conversation_facts'] = $facts;

            Conversation::withoutGlobalScopes()
                ->where('workspace_id', $workspaceId)
                ->whereKey($conversationId)
                ->update(['metadata' => $metadata]);

            $conversation->metadata = $metadata;
        }

        return $facts;
    }
}
