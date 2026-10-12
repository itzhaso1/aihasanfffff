<?php

namespace App\Enums\ChatCatalog;

enum ChatCatalogAvailabilityMode: string
{
    case AlwaysOpen = 'always_open';
    case Scheduled = 'scheduled';
    case TemporarilyUnavailable = 'temporarily_unavailable';
    case Maintenance = 'maintenance';
}
