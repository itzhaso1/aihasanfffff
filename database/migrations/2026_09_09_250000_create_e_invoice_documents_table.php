<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('e_invoice_documents')) {
            return;
        }

        Schema::create('e_invoice_documents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('issued_document_snapshot_id')
                ->constrained('issued_document_snapshots')
                ->cascadeOnDelete();
            $table->string('source_type', 64);
            $table->unsignedBigInteger('source_id');
            $table->string('document_kind', 64);
            $table->string('type_code', 8)->nullable();
            $table->string('transaction_code', 16)->nullable();
            $table->string('document_number');
            $table->date('issue_date')->nullable();
            $table->timestamp('issued_at')->nullable();
            $table->char('currency', 3)->default('SAR');
            $table->string('compliance_status', 32);
            $table->json('payload');
            $table->timestamps();

            $table->unique('issued_document_snapshot_id', 'e_invoice_docs_snapshot_unique');
            $table->unique(
                ['workspace_id', 'source_type', 'source_id'],
                'e_invoice_docs_source_unique'
            );
            $table->index(
                ['workspace_id', 'compliance_status'],
                'e_invoice_docs_compliance_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('e_invoice_documents');
    }
};
