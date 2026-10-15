<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Resources;

use App\Enums\ConversationHandoffStatus;
use App\Http\Resources\Mobile\ConversationResource;
use App\Models\Conversation;
use App\Services\Conversation\ConversationHandoffSummaryBuilder;
use Illuminate\Http\Request;
use Tests\TestCase;

class ConversationResourceHandoffSummaryTest extends TestCase
{
    public function test_handoff_summary_reflects_live_columns_not_stale_metadata_snapshot(): void
    {
        $conversation = new Conversation([
            'id' => 6,
            'workspace_id' => 1,
            'status' => 'open',
            'ai_enabled' => true,
            'handoff_status' => ConversationHandoffStatus::None,
            'metadata' => [
                'handoff' => [
                    'structured' => [
                        'ai_state' => 'disabled',
                        'handoff_status' => 'resolved',
                    ],
                ],
            ],
        ]);
        $conversation->exists = true;

        $this->app->instance(ConversationHandoffSummaryBuilder::class, new class
        {
            public function build(Conversation $conversation): array
            {
                $status = $conversation->handoff_status;

                return [
                    'ai_state' => $conversation->ai_enabled ? 'enabled' : 'disabled',
                    'handoff_status' => $status instanceof ConversationHandoffStatus
                        ? $status->value
                        : (string) ($status ?? 'none'),
                ];
            }
        });

        $payload = (new ConversationResource($conversation))->toArray(Request::create('/'));

        $this->assertTrue($payload['ai_enabled']);
        $this->assertSame('none', $payload['handoff_status']);
        $this->assertTrue($payload['ai_replying']);
        $this->assertSame('enabled', $payload['handoff_summary']['ai_state'] ?? null);
        $this->assertSame('none', $payload['handoff_summary']['handoff_status'] ?? null);
        $this->assertNotSame(
            data_get($conversation->metadata, 'handoff.structured.ai_state'),
            $payload['handoff_summary']['ai_state'] ?? null,
        );
    }
}
