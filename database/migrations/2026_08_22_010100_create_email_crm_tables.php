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
        Schema::create('email_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('email');
            $table->text('password');
            $table->string('imap_host');
            $table->unsignedSmallInteger('imap_port')->default(993);
            $table->string('smtp_host');
            $table->unsignedSmallInteger('smtp_port')->default(587);
            $table->string('logo_path')->nullable();
            $table->string('brand_color', 7)->default('#06C2A4');
            $table->json('aliases')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['workspace_id', 'email']);
            $table->index(['workspace_id', 'name']);
        });

        Schema::create('email_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('email_account_id')->constrained('email_accounts')->cascadeOnDelete();
            $table->string('sender');
            $table->string('recipient');
            $table->string('subject')->nullable();
            $table->longText('body')->nullable();
            $table->enum('type', ['inbound', 'outbound']);
            $table->string('message_id')->nullable();
            $table->string('in_reply_to')->nullable();
            $table->string('thread_key')->nullable();
            $table->timestamps();

            $table->index(['workspace_id', 'email_account_id', 'type']);
            $table->index(['workspace_id', 'thread_key']);
            $table->index(['workspace_id', 'created_at']);
            $table->unique(['workspace_id', 'email_account_id', 'message_id']);
        });

        Schema::create('email_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('message_id')->constrained('email_messages')->cascadeOnDelete();
            $table->string('file_path');
            $table->string('file_type')->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->timestamps();

            $table->index(['message_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('email_attachments');
        Schema::dropIfExists('email_messages');
        Schema::dropIfExists('email_accounts');
    }
};
