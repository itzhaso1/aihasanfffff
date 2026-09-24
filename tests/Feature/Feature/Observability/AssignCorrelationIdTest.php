<?php

namespace Tests\Feature\Feature\Observability;

use Tests\TestCase;

class AssignCorrelationIdTest extends TestCase
{
    public function test_meta_webhook_verify_returns_correlation_header(): void
    {
        $response = $this->get('/meta-webhook?hub.mode=subscribe&hub.verify_token=invalid&hub.challenge=123');

        $response->assertHeader('X-Correlation-Id');
    }
}
