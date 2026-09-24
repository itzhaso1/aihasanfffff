<?php

namespace App\Exceptions\Finance;

use App\Exceptions\Api\ApiApplicationException;
use App\Exceptions\Api\ApiErrorCode;
use App\Services\EInvoicing\ComplianceAssessment;
use Throwable;

class ZatcaRequiredIssueBlockedException extends ApiApplicationException
{
    public function __construct(
        string $message,
        public readonly ComplianceAssessment $assessment,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, ApiErrorCode::CannotIssue, 422, $previous);
    }

    /**
     * @return array<string, mixed>
     */
    public function compliancePayload(): array
    {
        return $this->assessment->compliancePayload();
    }
}
