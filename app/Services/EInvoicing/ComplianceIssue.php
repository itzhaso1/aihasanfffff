<?php

namespace App\Services\EInvoicing;

/**
 * Structured compliance / data-readiness issue for API and UI.
 */
final readonly class ComplianceIssue
{
    public function __construct(
        public string $code,
        public string $field,
        public string $message,
        public string $severity = 'error',
        public ?string $source = null,
    ) {}

    /**
     * @return array<string, string>
     */
    public function toArray(): array
    {
        $payload = [
            'code' => $this->code,
            'field' => $this->field,
            'message' => $this->message,
            'severity' => $this->severity,
        ];

        if ($this->source !== null && $this->source !== '') {
            $payload['source'] = $this->source;
        }

        return $payload;
    }
}
