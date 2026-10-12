<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Scope webhook dedup to tenant: the same external_event_id may exist once per workspace.
     *
     * Existing rows are preserved. The previous global unique index prevented cross-workspace
     * collisions but blocked legitimate multi-tenant isolation when two workspaces shared
     * provider-level identifiers.
     */
    public function up(): void
    {
        Schema::table('webhook_events', function (Blueprint $table): void {
            $table->dropUnique(['provider', 'idempotency_key']);
            $table->dropUnique(['provider', 'external_event_id']);
            $table->unique(
                ['workspace_id', 'provider', 'idempotency_key'],
                'webhook_events_workspace_provider_idempotency_key_unique'
            );
            $table->unique(
                ['workspace_id', 'provider', 'external_event_id'],
                'webhook_events_workspace_provider_external_event_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::table('webhook_events', function (Blueprint $table): void {
            $table->dropUnique('webhook_events_workspace_provider_idempotency_key_unique');
            $table->dropUnique('webhook_events_workspace_provider_external_event_unique');
            $table->unique(['provider', 'idempotency_key']);
            $table->unique(['provider', 'external_event_id']);
        });
    }
};
