<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('egs_onboarding')) {
            return;
        }

        Schema::create('egs_onboarding', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('egs_unit_id')->constrained('egs_units')->restrictOnDelete();
            $table->string('environment', 16);
            $table->string('status', 32)->default('unconfigured');
            $table->foreignId('private_key_reference_id')
                ->nullable()
                ->constrained('egs_private_key_references')
                ->nullOnDelete();
            $table->text('csr_pem')->nullable();
            $table->string('csr_fingerprint_sha256', 128)->nullable();
            $table->timestamp('csr_generated_at')->nullable();
            $table->string('failure_code', 64)->nullable();
            $table->text('failure_message')->nullable();
            $table->timestamps();

            $table->unique(['egs_unit_id', 'environment'], 'egs_onboarding_egs_env_unique');
            $table->index(['workspace_id', 'egs_unit_id'], 'egs_onboarding_workspace_egs_idx');
            $table->index(['workspace_id', 'environment', 'status'], 'egs_onboarding_workspace_env_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('egs_onboarding');
    }
};
