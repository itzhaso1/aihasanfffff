<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('issued_document_snapshots')) {
            return;
        }

        Schema::create('issued_document_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('source_type', 64);
            $table->unsignedBigInteger('source_id');
            $table->string('document_number');
            $table->date('issue_date');
            $table->timestamp('issued_at')->nullable();
            $table->char('currency', 3)->default('SAR');
            $table->json('payload');
            $table->timestamps();

            $table->unique(
                ['workspace_id', 'source_type', 'source_id'],
                'issued_doc_snapshots_source_unique'
            );
            $table->index(['workspace_id', 'document_number'], 'issued_doc_snapshots_number_idx');
            $table->index(['workspace_id', 'issue_date'], 'issued_doc_snapshots_issue_date_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('issued_document_snapshots');
    }
};
