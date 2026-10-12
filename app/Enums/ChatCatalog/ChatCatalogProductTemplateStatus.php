<?php

namespace App\Enums\ChatCatalog;

enum ChatCatalogProductTemplateStatus: string
{
    case Active = 'active';
    case Draft = 'draft';
    case Archived = 'archived';
}
