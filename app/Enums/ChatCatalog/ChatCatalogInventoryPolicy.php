<?php

namespace App\Enums\ChatCatalog;

enum ChatCatalogInventoryPolicy: string
{
    case NotTracked = 'not_tracked';
    case FiniteStock = 'finite_stock';
    case Unlimited = 'unlimited';
    case Backorder = 'backorder';
    case OnRequest = 'on_request';
    case DigitalPool = 'digital_pool';
    case FileAsset = 'file_asset';
    case ServiceCapacity = 'service_capacity';

    public function isSelectable(): bool
    {
        return $this !== self::Backorder;
    }

    /**
     * @return list<self>
     */
    public static function selectable(): array
    {
        return array_values(array_filter(
            self::cases(),
            static fn (self $policy): bool => $policy->isSelectable(),
        ));
    }

    /**
     * @return list<string>
     */
    public static function selectableValues(): array
    {
        return array_map(
            static fn (self $policy): string => $policy->value,
            self::selectable(),
        );
    }

    public function label(): string
    {
        return match ($this) {
            self::NotTracked => 'متاح دائمًا',
            self::FiniteStock, self::Backorder => 'كمية محددة',
            self::Unlimited => 'غير محدود',
            self::OnRequest => 'يُجهز عند الطلب',
            self::DigitalPool => 'أكواد أو تراخيص',
            self::FileAsset => 'ملف للتحميل',
            self::ServiceCapacity => 'سعة خدمة',
        };
    }

    public function description(): ?string
    {
        return match ($this) {
            self::NotTracked => 'المنتج متاح للبيع دائمًا بدون متابعة كمية.',
            self::FiniteStock => 'حدد عدد الوحدات المتاحة للبيع.',
            self::Unlimited => 'يمكن بيع المنتج بدون حد للكمية.',
            self::OnRequest => 'يتم تجهيز المنتج عند وصول الطلب.',
            self::DigitalPool => 'مناسب لأكواد التفعيل والمفاتيح والتراخيص الرقمية.',
            self::FileAsset => 'مناسب للملفات الرقمية مثل PDF وZIP.',
            self::Backorder, self::ServiceCapacity => null,
        };
    }
}
