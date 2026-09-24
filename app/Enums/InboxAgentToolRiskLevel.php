<?php

namespace App\Enums;

enum InboxAgentToolRiskLevel: string
{
    case Read = 'read';
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';
    case Critical = 'critical';
}
