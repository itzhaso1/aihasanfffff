<?php

namespace App\Enums\Commerce;

enum CommerceEvaluationStatus: string
{
    case Compatible = 'compatible';
    case Incompatible = 'incompatible';
    case InsufficientInformation = 'insufficient_information';
    case ConditionallySuitable = 'conditionally_suitable';
}
