<?php

declare(strict_types=1);

namespace App\AI\Evaluation;

enum EvaluationCategory: string
{
    case Truth = 'truth';
    case Security = 'security';
    case Policy = 'policy';
    case ToolAction = 'tool_action';
    case Quality = 'quality';
    case Persona = 'persona';
    case Runtime = 'runtime';
}
