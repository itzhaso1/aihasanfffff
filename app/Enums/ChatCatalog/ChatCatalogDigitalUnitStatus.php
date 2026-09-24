<?php

namespace App\Enums\ChatCatalog;

enum ChatCatalogDigitalUnitStatus: string
{
    case Available = 'available';
    case Reserved = 'reserved';
    case Delivered = 'delivered';
    case Consumed = 'consumed';
    case Expired = 'expired';
    case Revoked = 'revoked';
    case Disabled = 'disabled';

    /**
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Available => [self::Reserved, self::Disabled, self::Expired, self::Revoked],
            self::Reserved => [self::Available, self::Delivered, self::Consumed, self::Expired, self::Revoked],
            self::Delivered => [self::Consumed, self::Revoked],
            self::Consumed, self::Expired, self::Revoked, self::Disabled => [],
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->allowedTransitions(), true);
    }
}
