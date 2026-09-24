<?php

namespace App\Enums\ChatCatalog;

enum ChatCatalogOperatingStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Paused = 'paused';
    case Archived = 'archived';

    public function allowsCustomerDiscovery(): bool
    {
        return $this === self::Published;
    }

    public function allowsCustomerDiscoveryWhenPaused(): bool
    {
        return $this === self::Paused;
    }
}
