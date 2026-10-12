<?php

namespace App\Enums\ChatCatalog;

enum ChatCatalogProductTemplateFieldType: string
{
    case Text = 'text';
    case Textarea = 'textarea';
    case Number = 'number';
    case Boolean = 'boolean';
    case Select = 'select';
    case Measurement = 'measurement';
    case Money = 'money';
}
