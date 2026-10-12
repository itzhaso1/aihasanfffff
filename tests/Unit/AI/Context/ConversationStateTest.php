<?php

declare(strict_types=1);

namespace Tests\Unit\AI\Context;

use App\AI\Context\ConversationState;
use PHPUnit\Framework\TestCase;

class ConversationStateTest extends TestCase
{
    public function test_default_state_is_explicit_and_serializable(): void
    {
        $state = new ConversationState(employeeId: 7);

        $this->assertSame([
            'employee_id' => 7,
            'introduced' => false,
            'sales_stage' => null,
            'last_intent' => null,
            'referenced_products' => [],
        ], $state->toArray());
    }

    public function test_state_keeps_only_identifier_fields_from_metadata(): void
    {
        $state = ConversationState::fromConversationMetadata([
            'ai' => [
                'state' => [
                    'employee_id' => 3,
                    'introduced' => true,
                    'sales_stage' => 'considering',
                    'last_intent' => 'price_question',
                    'referenced_products' => [
                        ['catalog_item_id' => 9, 'price' => 100, 'stock' => 3],
                        ['id' => 5, 'availability' => true],
                        9,
                        'bad',
                    ],
                ],
            ],
        ]);

        $this->assertSame(3, $state->employeeId);
        $this->assertTrue($state->introduced);
        $this->assertSame('considering', $state->salesStage);
        $this->assertSame('price_question', $state->lastIntent);
        $this->assertSame([5, 9], $state->referencedProductIds);
    }

    public function test_state_update_methods_are_immutable(): void
    {
        $state = new ConversationState(employeeId: 7);
        $updated = $state
            ->withIntroduced(true)
            ->withSalesStage('qualified')
            ->withLastIntent('purchase_intent')
            ->withReferencedProducts([4, 2, 4]);

        $this->assertFalse($state->introduced);
        $this->assertNull($state->salesStage);
        $this->assertTrue($updated->introduced);
        $this->assertSame('qualified', $updated->salesStage);
        $this->assertSame('purchase_intent', $updated->lastIntent);
        $this->assertSame([2, 4], $updated->referencedProductIds);
        $this->assertSame(7, $updated->employeeId);
    }
}
