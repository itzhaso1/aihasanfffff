<?php

namespace Tests\Unit\Chat;

use App\Enums\ChatCatalog\ChatCatalogInventoryPolicy;
use Tests\TestCase;

class ChatCatalogInventoryPolicyTest extends TestCase
{
    public function test_selectable_values_omit_legacy_backorder(): void
    {
        $this->assertSame([
            'not_tracked',
            'finite_stock',
            'unlimited',
            'on_request',
            'digital_pool',
            'file_asset',
            'service_capacity',
        ], ChatCatalogInventoryPolicy::selectableValues());
        $this->assertFalse(ChatCatalogInventoryPolicy::Backorder->isSelectable());
        $this->assertSame('backorder', ChatCatalogInventoryPolicy::Backorder->value);
    }

    public function test_user_facing_labels_and_descriptions_are_arabic(): void
    {
        $this->assertSame('متاح دائمًا', ChatCatalogInventoryPolicy::NotTracked->label());
        $this->assertSame('كمية محددة', ChatCatalogInventoryPolicy::FiniteStock->label());
        $this->assertSame('كمية محددة', ChatCatalogInventoryPolicy::Backorder->label());
        $this->assertSame('غير محدود', ChatCatalogInventoryPolicy::Unlimited->label());
        $this->assertSame('يُجهز عند الطلب', ChatCatalogInventoryPolicy::OnRequest->label());
        $this->assertSame('أكواد أو تراخيص', ChatCatalogInventoryPolicy::DigitalPool->label());
        $this->assertSame('ملف للتحميل', ChatCatalogInventoryPolicy::FileAsset->label());
        $this->assertSame('المنتج متاح للبيع دائمًا بدون متابعة كمية.', ChatCatalogInventoryPolicy::NotTracked->description());
        $this->assertSame('حدد عدد الوحدات المتاحة للبيع.', ChatCatalogInventoryPolicy::FiniteStock->description());
        $this->assertNull(ChatCatalogInventoryPolicy::Backorder->description());
    }
}
