<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('e_invoice_certificates')) {
            return;
        }

        Schema::create('e_invoice_certificates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('egs_unit_id')->constrained('egs_units')->restrictOnDelete();
            $table->uuid('certificate_identifier');
            $table->string('serial_number', 128);
            $table->text('subject');
            $table->text('issuer');
            $table->dateTime('not_before');
            $table->dateTime('not_after');
            $table->string('fingerprint_sha256', 64);
            $table->text('public_certificate');
            $table->string('public_key_algorithm', 32);
            $table->string('signature_algorithm', 64)->nullable();
            $table->string('curve', 32)->nullable();
            $table->unsignedSmallInteger('key_length_bits')->nullable();
            $table->string('status', 32);
            $table->boolean('test_fixture')->default(false);
            $table->timestamps();

            $table->unique('certificate_identifier', 'e_invoice_certificates_identifier_unique');
            $table->unique(['workspace_id', 'fingerprint_sha256'], 'e_invoice_certificates_workspace_fingerprint_unique');
            $table->index(['workspace_id', 'egs_unit_id'], 'e_invoice_certificates_workspace_egs_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('e_invoice_certificates');
    }
};
