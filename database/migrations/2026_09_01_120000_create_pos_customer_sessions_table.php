<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_customer_sessions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('dining_table_id')->constrained('dining_tables')->cascadeOnDelete();
            $table->foreignId('table_session_id')->constrained('table_sessions')->cascadeOnDelete();
            $table->string('token', 80)->unique();
            $table->string('status', 32)->default('active'); // active|revoked
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['workspace_id', 'dining_table_id', 'status'], 'pos_customer_sessions_table_status_idx');
            $table->index(['table_session_id', 'status'], 'pos_customer_sessions_session_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_customer_sessions');
    }
};
