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
        Schema::create('contracts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->string('contract_number', 64);
            $table->string('title');
            $table->enum('status', ['draft', 'open', 'closed', 'cancelled'])->default('draft');
            $table->decimal('value', 14, 2)->default(0);
            $table->string('currency', 3)->default('SAR');
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('terms')->nullable();
            $table->text('notes')->nullable();
            $table->json('company_snapshot')->nullable();
            $table->json('customer_snapshot')->nullable();
            $table->json('pdf_snapshot')->nullable();
            $table->json('metadata')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['workspace_id', 'contract_number']);
            $table->index(['workspace_id', 'status']);
            $table->index(['workspace_id', 'start_date', 'end_date']);
        });

        Schema::create('contract_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contract_id')->constrained('contracts')->cascadeOnDelete();
            $table->unsignedInteger('sort_order')->default(0);
            $table->string('title');
            $table->text('description')->nullable();
            $table->decimal('quantity', 12, 3)->default(1);
            $table->decimal('unit_price', 14, 2)->default(0);
            $table->decimal('total', 14, 2)->default(0);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['workspace_id', 'contract_id']);
        });

        Schema::create('contract_attachments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contract_id')->constrained('contracts')->cascadeOnDelete();
            $table->string('file_path');
            $table->string('file_name')->nullable();
            $table->string('file_type')->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->timestamps();

            $table->index(['workspace_id', 'contract_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('contract_attachments');
        Schema::dropIfExists('contract_items');
        Schema::dropIfExists('contracts');
    }
};
