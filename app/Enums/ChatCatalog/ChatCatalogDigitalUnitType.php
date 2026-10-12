<?php

namespace App\Enums\ChatCatalog;

enum ChatCatalogDigitalUnitType: string
{
    case Code = 'code';
    case License = 'license';
    case Credential = 'credential';
    case Subscription = 'subscription';
    case File = 'file';
    case Custom = 'custom';
}
