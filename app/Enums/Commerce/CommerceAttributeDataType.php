<?php

namespace App\Enums\Commerce;

enum CommerceAttributeDataType: string
{
    case String = 'string';
    case Integer = 'integer';
    case Decimal = 'decimal';
    case Boolean = 'boolean';
    case Enum = 'enum';
    case Date = 'date';
    case DateTime = 'datetime';
    case Measurement = 'measurement';
    case Dimension = 'dimension';
    case Range = 'range';
    case Reference = 'reference';
    case MultiValue = 'multi_value';
}
