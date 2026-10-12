<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('egs_onboarding')) {
            return;
        }

        Schema::table('egs_onboarding', function (Blueprint $table): void {
            if (! Schema::hasColumn('egs_onboarding', 'external_request_id')) {
                $table->string('external_request_id', 128)->nullable()->after('failure_message');
            }
            if (! Schema::hasColumn('egs_onboarding', 'otp_phase')) {
                $table->string('otp_phase', 32)->default('none')->after('external_request_id');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('egs_onboarding')) {
            return;
        }

        Schema::table('egs_onboarding', function (Blueprint $table): void {
            foreach (['otp_phase', 'external_request_id'] as $column) {
                if (Schema::hasColumn('egs_onboarding', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
