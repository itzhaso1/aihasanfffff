<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('workspace_meta_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 32);
            $table->string('external_id', 191);
            $table->foreignId('parent_connection_id')
                ->nullable()
                ->constrained('workspace_meta_connections')
                ->nullOnDelete();
            $table->string('display_name')->nullable();
            $table->enum('status', ['pending', 'connected', 'disconnected', 'error'])->default('pending');
            $table->text('access_token_ciphertext')->nullable();
            $table->timestamp('token_expires_at')->nullable();
            $table->json('scopes')->nullable();
            $table->json('metadata')->nullable();
            $table->foreignId('connected_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['workspace_id', 'kind', 'external_id'], 'workspace_meta_connections_tenant_kind_ext_unique');
            $table->index(['workspace_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('workspace_meta_connections');
    }
};
