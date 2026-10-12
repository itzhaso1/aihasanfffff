<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('egs_active_identities')) {
            return;
        }

        Schema::create('egs_active_identities', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('egs_unit_id')->constrained('egs_units')->restrictOnDelete();
            $table->string('environment', 16);
            $table->string('purpose', 32);
            $table->string('status', 16)->default('active');
            $table->foreignId('egs_credential_id')->constrained('egs_credentials')->restrictOnDelete();
            $table->foreignId('e_invoice_certificate_id')->constrained('e_invoice_certificates')->restrictOnDelete();
            $table->foreignId('private_key_reference_id')->constrained('egs_private_key_references')->restrictOnDelete();
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('deactivated_at')->nullable();
            $table->timestamps();

            $table->index(['workspace_id', 'egs_unit_id', 'environment'], 'egs_active_identities_workspace_egs_env_idx');
            $table->index(['egs_unit_id', 'environment', 'purpose', 'status'], 'egs_active_identities_egs_env_purpose_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('egs_active_identities');
    }
};
