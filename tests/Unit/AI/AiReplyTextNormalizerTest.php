<?php

namespace Tests\Unit\AI;

use App\Services\AI\AiReplyTextNormalizer;
use Tests\TestCase;

class AiReplyTextNormalizerTest extends TestCase
{
    public function test_strips_arabic_diacritics_but_keeps_hamza_letters(): void
    {
        $normalizer = new AiReplyTextNormalizer;

        $this->assertSame('مرحبا', $normalizer->normalize('مَرْحَبًا'));
        $this->assertSame('متوفر ومستعد', $normalizer->normalize('مُتَوَفِّر ومُسْتَعِد'));
        $this->assertSame('أبشر إن شاء الله', $normalizer->normalize('أبشر إن شاء الله'));
    }

    public function test_formal_sentence_periods_become_chat_lines(): void
    {
        $normalizer = new AiReplyTextNormalizer;

        $this->assertSame(
            "المنتج متوفر\nسعره 100 ريال\nاذا ودك نكمل الطلب",
            $normalizer->normalize('المنتج متوفر. سعره 100 ريال. اذا ودك نكمل الطلب.'),
        );
        $this->assertSame('كيف اساعدك ؟', $normalizer->normalize('كيف اساعدك ؟'));
        $this->assertSame('سعره 99.5 ريال', $normalizer->normalize('سعره 99.5 ريال.'));
        $this->assertSame('Price is 10.', $normalizer->normalize('Price is 10.'));
    }

    public function test_truncated_reply_drops_the_incomplete_tail(): void
    {
        $normalizer = new AiReplyTextNormalizer;

        $this->assertSame(
            "ايه موجود\nسعره 299 ريال",
            $normalizer->normalize("ايه موجود\nسعره 299 ريال\nواذا ودك اعط", truncated: true),
        );
        $this->assertSame(
            'ايه متوفر وسعره 299 ريال',
            $normalizer->normalize('ايه متوفر وسعره 299 ريال والتوصي', truncated: true),
        );
        $this->assertSame(
            'ايه متوفر وسعره 299 ريال والتوصيل مجاني',
            $normalizer->normalize('ايه متوفر وسعره 299 ريال والتوصيل مجاني'),
        );
    }

    public function test_length_cap_never_cuts_a_word(): void
    {
        $normalizer = new AiReplyTextNormalizer;

        $limited = $normalizer->limit('ايه متوفر وسعره مناسب جدا', 12);

        $this->assertSame('ايه متوفر', $limited);
        $this->assertSame('قصير', $normalizer->limit('قصير', 12));
    }

    public function test_detects_provider_token_limit_stops(): void
    {
        $this->assertTrue(AiReplyTextNormalizer::providerStoppedAtLimit(['raw' => ['candidates' => [['finishReason' => 'MAX_TOKENS']]]]));
        $this->assertTrue(AiReplyTextNormalizer::providerStoppedAtLimit(['raw' => ['choices' => [['finish_reason' => 'length']]]]));
        $this->assertFalse(AiReplyTextNormalizer::providerStoppedAtLimit(['raw' => ['candidates' => [['finishReason' => 'STOP']]]]));
        $this->assertFalse(AiReplyTextNormalizer::providerStoppedAtLimit(['raw' => []]));
    }
}
