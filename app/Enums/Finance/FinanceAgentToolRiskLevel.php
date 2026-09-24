<?php

namespace App\Enums\Finance;

enum FinanceAgentToolRiskLevel: string
{
    case Read = 'read';
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';
    case Critical = 'critical';
}
