<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('e_invoice_security_records')) {
            return;
        }

        Schema::create('e_invoice_security_records', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('egs_unit_id')->constrained('egs_units')->restrictOnDelete();
            $table->foreignId('e_invoice_document_id')
                ->constrained('e_invoice_documents')
                ->restrictOnDelete();
            $table->unsignedBigInteger('icv');
            $table->text('pih');
            $table->text('invoice_hash');
            $table->string('hash_algorithm', 32);
            $table->string('canonicalization_method', 128);
            $table->string('source_xml_digest', 64);
            $table->string('security_status', 32);
            $table->timestamp('finalized_at')->nullable();
            $table->timestamps();

            $table->unique('e_invoice_document_id', 'e_invoice_security_document_unique');
            $table->unique(['egs_unit_id', 'icv'], 'e_invoice_security_egs_icv_unique');
            $table->index(['workspace_id', 'egs_unit_id'], 'e_invoice_security_workspace_egs_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('e_invoice_security_records');
    }
};
