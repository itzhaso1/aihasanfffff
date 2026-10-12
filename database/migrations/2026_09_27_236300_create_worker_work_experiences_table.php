<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('worker_work_experiences', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('worker_profile_id')
                ->constrained('worker_profiles')
                ->cascadeOnDelete();
            $table->string('title', 255);
            $table->string('organization_name', 255);
            $table->date('started_on');
            $table->date('ended_on')->nullable();
            $table->boolean('is_current')->default(false);
            $table->text('description')->nullable();
            $table->timestamps();

            $table->index(['worker_profile_id', 'started_on'], 'wwe_profile_started_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('worker_work_experiences');
    }
};
