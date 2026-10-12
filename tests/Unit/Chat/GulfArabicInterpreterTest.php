<?php

namespace Tests\Unit\Chat;

use App\Support\Chat\GulfArabicInterpreter;
use Tests\TestCase;

class GulfArabicInterpreterTest extends TestCase
{
    public function test_request_and_availability_do_not_replace_the_product_token(): void
    {
        $reading = app(GulfArabicInterpreter::class)->interpret('عندكم فساتين؟');

        $this->assertSame('عندكم فساتين؟', $reading['original']);
        $this->assertContains('availability', $reading['intents']);
        $this->assertContains('فساتين', $reading['search_tokens']);
        $this->assertNotContains('عندكم', $reading['search_tokens']);
    }

    public function test_price_and_location_phrases_are_intents_not_product_tokens(): void
    {
        $price = app(GulfArabicInterpreter::class)->interpret('كم سعره الفستان');
        $this->assertContains('price', $price['intents']);
        $this->assertContains('الفستان', $price['search_tokens']);

        $where = app(GulfArabicInterpreter::class)->interpret('وين الفساتين');
        $this->assertContains('location', $where['intents']);
        $this->assertContains('الفساتين', $where['search_tokens']);
    }

    public function test_distinctive_marker_hints_one_gulf_variety(): void
    {
        $reading = app(GulfArabicInterpreter::class)->interpret('أبغى الفستان دحين');

        $this->assertSame('saudi', $reading['dialect_hint']);
        $this->assertContains('request', $reading['intents']);
        $this->assertContains('now', $reading['intents']);
    }

    public function test_uncertain_text_has_no_forced_dialect(): void
    {
        $reading = app(GulfArabicInterpreter::class)->interpret('عندكم فستان');

        $this->assertNull($reading['dialect_hint']);
    }

    public function test_short_follow_ups_are_intents_not_product_tokens(): void
    {
        foreach (['كم', 'ابي هذا', 'طيب', 'كم حقه', 'السعر'] as $message) {
            $reading = app(GulfArabicInterpreter::class)->interpret($message);

            $this->assertSame([], $reading['search_tokens'], $message);
            $this->assertNotSame([], $reading['intents'], $message);
        }
    }
}
