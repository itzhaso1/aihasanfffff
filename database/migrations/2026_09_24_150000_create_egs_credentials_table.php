<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('egs_credentials')) {
            return;
        }

        Schema::create('egs_credentials', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('egs_unit_id')->constrained('egs_units')->restrictOnDelete();
            $table->string('environment', 16);
            $table->string('kind', 16);
            $table->string('status', 16)->default('pending');
            $table->foreignId('e_invoice_certificate_id')
                ->nullable()
                ->constrained('e_invoice_certificates')
                ->nullOnDelete();
            $table->foreignId('private_key_reference_id')
                ->nullable()
                ->constrained('egs_private_key_references')
                ->restrictOnDelete();
            $table->string('secret_ref', 128)->nullable();
            $table->string('secret_store_driver', 32)->nullable();
            $table->string('external_request_id', 128)->nullable();
            $table->timestamp('issued_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            $table->index(['workspace_id', 'egs_unit_id', 'environment'], 'egs_credentials_workspace_egs_env_idx');
            $table->index(['workspace_id', 'egs_unit_id', 'environment', 'kind'], 'egs_credentials_workspace_egs_env_kind_idx');
            $table->index(['workspace_id', 'egs_unit_id', 'status'], 'egs_credentials_workspace_egs_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('egs_credentials');
    }
};
