<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('egs_private_key_references')) {
            return;
        }

        Schema::create('egs_private_key_references', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('egs_unit_id')->constrained('egs_units')->restrictOnDelete();
            $table->string('environment', 16);
            $table->string('store_driver', 32);
            $table->string('store_key', 128);
            $table->string('public_key_fingerprint', 128);
            $table->string('status', 16)->default('active');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('destroyed_at')->nullable();
            $table->timestamps();

            $table->unique('store_key', 'egs_private_key_refs_store_key_unique');
            $table->index(
                ['workspace_id', 'egs_unit_id', 'environment'],
                'egs_private_key_refs_workspace_egs_env_idx',
            );
            $table->index(
                ['workspace_id', 'egs_unit_id', 'environment', 'status'],
                'egs_private_key_refs_workspace_egs_env_status_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('egs_private_key_references');
    }
};
