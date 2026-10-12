<?php

declare(strict_types=1);

namespace App\AI\Core;

enum AiRuntimeStage: string
{
    case ResolveEmployee = 'resolve_employee';
    case LoadConstitution = 'load_constitution';
    case LoadPolicy = 'load_policy';
    case BuildContext = 'build_context';
    case ResolveTraining = 'resolve_training';
    case ResolveTools = 'resolve_tools';
    case ComposePrompt = 'compose_prompt';
    case CallModel = 'call_model';
    case RunTool = 'run_tool';
    case GuardResponse = 'guard_response';
    case Runtime = 'runtime';
}
