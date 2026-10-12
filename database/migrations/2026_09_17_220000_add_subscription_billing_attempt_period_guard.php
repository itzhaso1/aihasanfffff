<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const ACTIVE_STATUSES = ['pending', 'processing', 'paid'];

    public function up(): void
    {
        Schema::table('subscription_billing_attempts', function (Blueprint $table): void {
            $table->string('active_period_guard', 64)->nullable()->after('period_key');
        });

        DB::table('subscription_billing_attempts')
            ->whereIn('status', self::ACTIVE_STATUSES)
            ->whereNotNull('subscription_id')
            ->whereNotNull('period_key')
            ->where('period_key', '!=', '')
            ->update([
                'active_period_guard' => DB::raw('period_key'),
            ]);

        $conflicts = DB::table('subscription_billing_attempts')
            ->select('subscription_id', 'purpose', 'active_period_guard', DB::raw('COUNT(*) as total'))
            ->whereNotNull('subscription_id')
            ->whereNotNull('active_period_guard')
            ->groupBy('subscription_id', 'purpose', 'active_period_guard')
            ->having('total', '>', 1)
            ->get();

        if ($conflicts->isNotEmpty()) {
            throw new RuntimeException(
                'Cannot add subscription billing period guard: duplicate active attempts exist. Resolve them before migrating.'
            );
        }

        Schema::table('subscription_billing_attempts', function (Blueprint $table): void {
            $table->unique(
                ['subscription_id', 'purpose', 'active_period_guard'],
                'sub_bill_att_active_period_uidx'
            );
        });
    }

    public function down(): void
    {
        Schema::table('subscription_billing_attempts', function (Blueprint $table): void {
            $table->dropUnique('sub_bill_att_active_period_uidx');
            $table->dropColumn('active_period_guard');
        });
    }
};
