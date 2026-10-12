<?php

namespace App\Enums\ChatCatalog;

enum ChatCatalogItemClassification: string
{
    case Physical = 'physical';
    case Digital = 'digital';
    case Service = 'service';
}
