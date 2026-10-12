<?php

namespace Tests\Feature\Feature\Observability;

use App\Models\WebhookEvent;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class OpsPruneWebhookEventsTest extends TestCase
{
    use RefreshDatabase;

    public function test_prune_deletes_only_old_processed_rows(): void
    {
        $workspace = Workspace::factory()->create();

        WebhookEvent::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'provider' => 'whatsapp',
            'external_event_id' => 'old-processed',
            'event_type' => 'message',
            'idempotency_key' => 'old-processed',
            'headers' => [],
            'payload' => ['id' => 'old-processed'],
            'status' => 'processed',
            'processed_at' => now()->subDays(40),
        ]);

        WebhookEvent::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'provider' => 'whatsapp',
            'external_event_id' => 'pending',
            'event_type' => 'message',
            'idempotency_key' => 'pending',
            'headers' => [],
            'payload' => ['id' => 'pending'],
            'status' => 'pending',
        ]);

        Artisan::call('ops:retention:webhook-events', ['--days' => 30]);

        $this->assertDatabaseMissing('webhook_events', ['external_event_id' => 'old-processed']);
        $this->assertDatabaseHas('webhook_events', ['external_event_id' => 'pending']);
    }
}
