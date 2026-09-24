<?php

namespace App\Enums\Commerce;

enum CommerceRuleType: string
{
    case RequiredAttribute = 'required_attribute';
    case Incompatibility = 'incompatibility';
    case RecommendationConstraint = 'recommendation_constraint';
    case QuantityConstraint = 'quantity_constraint';
    case Prerequisite = 'prerequisite';
    case Alternative = 'alternative';
}
