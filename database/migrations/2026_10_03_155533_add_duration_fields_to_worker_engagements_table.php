<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('worker_engagements', function (Blueprint $table): void {
            $table->unsignedInteger('duration_value')->nullable()->after('ends_at');
            $table->string('duration_unit', 16)->nullable()->after('duration_value');
        });
    }

    public function down(): void
    {
        Schema::table('worker_engagements', function (Blueprint $table): void {
            $table->dropColumn(['duration_value', 'duration_unit']);
        });
    }
};
