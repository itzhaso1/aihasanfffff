<?php

namespace App\Enums\ChatCatalog;

enum ChatCatalogFormality: string
{
    case VeryFormal = 'very_formal';
    case Formal = 'formal';
    case Neutral = 'neutral';
    case Friendly = 'friendly';
    case Casual = 'casual';
}
