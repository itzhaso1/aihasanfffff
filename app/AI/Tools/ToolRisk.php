<?php

declare(strict_types=1);

namespace App\AI\Tools;

enum ToolRisk: string
{
    case Read = 'read';
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';
    case Critical = 'critical';
}
