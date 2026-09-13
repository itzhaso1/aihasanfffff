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
        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->enum('direction', ['inbound', 'outbound', 'internal_note'])->default('inbound');
            $table->enum('message_type', ['text', 'image', 'file', 'system'])->default('text');
            $table->longText('content')->nullable();
            $table->string('external_message_id')->nullable();
            $table->boolean('ai_generated')->default(false);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['workspace_id', 'external_message_id']);
            $table->index(['workspace_id', 'conversation_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('messages');
    }
};
