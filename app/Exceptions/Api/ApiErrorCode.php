<?php

namespace App\Exceptions\Api;

enum ApiErrorCode: string
{
    case Unauthorized = 'unauthorized';
    case Forbidden = 'forbidden';
    case NotFound = 'not_found';
    case ValidationFailed = 'validation_failed';
    case CannotIssue = 'cannot_issue';
    case AlreadyIssued = 'already_issued';
    case IdempotencyConflict = 'idempotency_conflict';
    case ComplianceUnavailable = 'compliance_unavailable';
    case ProductionCryptoUnavailable = 'production_crypto_unavailable';
}
