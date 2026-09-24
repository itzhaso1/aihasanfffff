<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_channel_identities', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->string('channel', 32);
            $table->string('external_user_id', 191);
            $table->string('username')->nullable();
            $table->string('display_name')->nullable();
            $table->string('avatar_storage_key')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(
                ['workspace_id', 'channel', 'external_user_id'],
                'customer_channel_identities_unique',
            );
            $table->index(['workspace_id', 'customer_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_channel_identities');
    }
};
