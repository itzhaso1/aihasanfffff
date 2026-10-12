<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('e_invoice_cryptographic_stamps')) {
            return;
        }

        Schema::create('e_invoice_cryptographic_stamps', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('egs_unit_id')->constrained('egs_units')->restrictOnDelete();
            $table->foreignId('e_invoice_document_id')
                ->constrained('e_invoice_documents')
                ->restrictOnDelete();
            $table->foreignId('e_invoice_certificate_id')
                ->constrained('e_invoice_certificates')
                ->restrictOnDelete();
            $table->text('invoice_hash');
            $table->string('signature_algorithm', 64);
            $table->text('signature_value');
            $table->text('public_key_spki');
            $table->string('signed_input_identifier', 64);
            $table->string('stamp_status', 32);
            $table->timestamp('finalized_at')->nullable();
            $table->timestamps();

            $table->unique('e_invoice_document_id', 'e_invoice_stamps_document_unique');
            $table->index(['workspace_id', 'egs_unit_id'], 'e_invoice_stamps_workspace_egs_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('e_invoice_cryptographic_stamps');
    }
};
