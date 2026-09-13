<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('egs_units')) {
            return;
        }

        Schema::create('egs_units', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->uuid('uuid');
            $table->string('name', 128);
            $table->unsignedBigInteger('next_icv')->default(1);
            $table->text('last_invoice_hash')->nullable();
            $table->string('status', 32)->default('active');
            $table->timestamps();

            $table->unique(['workspace_id', 'uuid'], 'egs_units_workspace_uuid_unique');
            $table->index(['workspace_id', 'status'], 'egs_units_workspace_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('egs_units');
    }
};
