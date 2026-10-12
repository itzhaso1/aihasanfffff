<?php

declare(strict_types=1);

namespace App\AI\Core\Data;

enum ModelFinishReason: string
{
    case Stop = 'stop';
    case Length = 'length';
    case ToolCalls = 'tool_calls';
    case Safety = 'safety';
    case Error = 'error';
}
