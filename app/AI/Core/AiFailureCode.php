<?php

declare(strict_types=1);

namespace App\AI\Core;

enum AiFailureCode: string
{
    case DependencyFailed = 'dependency_failed';
    case InvalidPrompt = 'invalid_prompt';
    case ModelError = 'model_error';
    case ModelSafetyStop = 'model_safety_stop';
    case EmptyModelResponse = 'empty_model_response';
    case ToolRoundsExceeded = 'tool_rounds_exceeded';
    case GuardRejected = 'guard_rejected';
    case Unexpected = 'unexpected';
}
