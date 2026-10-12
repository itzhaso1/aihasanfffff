<?php

declare(strict_types=1);

namespace App\AI\Evaluation;

enum EvaluationSeverity: string
{
    case Fail = 'fail';
    case Warn = 'warn';
}
