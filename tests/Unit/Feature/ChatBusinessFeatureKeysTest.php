<?php

namespace Tests\Unit\Feature;

use App\Support\Feature\ChatBusinessFeatureKeys;
use Tests\TestCase;

class ChatBusinessFeatureKeysTest extends TestCase
{
    public function test_except_strips_business_keys_and_keeps_chat_keys(): void
    {
        $this->assertSame(
            ['products', 'orders', 'ai'],
            ChatBusinessFeatureKeys::except(['products', 'finance', 'orders', 'pos', 'inventory', 'ai']),
        );
    }

    public function test_except_comparison_rows_hides_business_matrix_rows(): void
    {
        $rows = ChatBusinessFeatureKeys::exceptComparisonRows([
            ['key' => 'ai', 'label' => 'AI'],
            ['key' => 'pos', 'label' => 'POS'],
            ['key' => 'finance', 'label' => 'Finance'],
            ['key' => 'inventory', 'label' => 'Inventory'],
            ['key' => 'whatsapp', 'label' => 'WhatsApp'],
        ]);

        $this->assertSame(['ai', 'whatsapp'], array_column($rows, 'key'));
    }
}
