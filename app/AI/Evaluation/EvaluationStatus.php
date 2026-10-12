<?php

declare(strict_types=1);

namespace App\AI\Evaluation;

enum EvaluationStatus: string
{
    case Passed = 'passed';
    case PassedWithWarnings = 'passed_with_warnings';
    case Failed = 'failed';
}
