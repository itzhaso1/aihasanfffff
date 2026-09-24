<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_settings', function (Blueprint $table): void {
            if (! Schema::hasColumn('ai_settings', 'ai_role')) {
                $table->string('ai_role', 64)
                    ->default('general_business_assistant')
                    ->after('name');
            }
        });
    }

    public function down(): void
    {
        Schema::table('ai_settings', function (Blueprint $table): void {
            if (Schema::hasColumn('ai_settings', 'ai_role')) {
                $table->dropColumn('ai_role');
            }
        });
    }
};
