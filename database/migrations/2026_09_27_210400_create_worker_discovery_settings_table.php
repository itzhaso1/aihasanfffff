<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('worker_discovery_settings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('worker_profile_id')
                ->unique()
                ->constrained('worker_profiles')
                ->cascadeOnDelete();
            $table->boolean('is_discoverable')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('worker_discovery_settings');
    }
};
