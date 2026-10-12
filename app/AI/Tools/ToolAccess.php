<?php

declare(strict_types=1);

namespace App\AI\Tools;

enum ToolAccess: string
{
    case Read = 'read';
    case Write = 'write';
}
