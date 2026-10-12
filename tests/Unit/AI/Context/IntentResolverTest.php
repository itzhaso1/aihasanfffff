<?php

declare(strict_types=1);

namespace Tests\Unit\AI\Context;

use App\AI\Context\ConversationState;
use App\AI\Context\IntentResolver;
use Tests\TestCase;

class IntentResolverTest extends TestCase
{
    public function test_canonical_price_and_availability_mapping(): void
    {
        $resolver = app(IntentResolver::class);

        $price = $resolver->resolve('كم', new ConversationState);
        $availability = $resolver->resolve('والاسود موجود؟', new ConversationState);

        $this->assertSame('price_question', $price->canonical);
        $this->assertContains('price', $price->rawIntents);
        $this->assertSame('availability_question', $availability->canonical);
        $this->assertContains('availability', $availability->rawIntents);
    }

    public function test_request_maps_to_purchase_intent_only_when_product_context_exists(): void
    {
        $resolver = app(IntentResolver::class);

        $ambiguous = $resolver->resolve('ابي', new ConversationState, hasProductContext: false);
        $purchase = $resolver->resolve('ابي هذا', new ConversationState, hasProductContext: true);

        $this->assertNull($ambiguous->canonical);
        $this->assertContains('request', $ambiguous->rawIntents);
        $this->assertSame('purchase_intent', $purchase->canonical);
        $this->assertContains('request', $purchase->rawIntents);
    }

    public function test_contextual_followups_preserve_unknowns_and_can_fall_back_to_state(): void
    {
        $resolver = app(IntentResolver::class);
        $state = new ConversationState(lastIntent: 'price_question');

        $followup = $resolver->resolve('طيب', $state);
        $unknown = $resolver->resolve('مرحبا', new ConversationState);

        $this->assertSame('price_question', $followup->canonical);
        $this->assertTrue($followup->usedConversationState);
        $this->assertNull($unknown->canonical);
        $this->assertSame([], $unknown->rawIntents);
    }
}
