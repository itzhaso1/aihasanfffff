<?php

namespace App\Enums\EInvoicing;

enum ComplianceStatus: string
{
    case NotApplicable = 'not_applicable';
    case Ready = 'ready';
    case Generated = 'generated';
    case Queued = 'queued';
    case Submitted = 'submitted';
    case Cleared = 'cleared';
    case Reported = 'reported';
    case Rejected = 'rejected';
    case Failed = 'failed';
}
