<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workspace_tiktok_connections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('business_open_id', 191);
            $table->string('display_name')->nullable();
            $table->string('status', 32)->default('connected');
            $table->json('scopes')->nullable();
            $table->json('metadata')->nullable();
            $table->text('access_token_ciphertext')->nullable();
            $table->text('refresh_token_ciphertext')->nullable();
            $table->timestamp('token_expires_at')->nullable();
            $table->foreignId('connected_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['workspace_id', 'business_open_id'], 'workspace_tiktok_connections_tenant_open_id_unique');
            $table->index(['business_open_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workspace_tiktok_connections');
    }
};
