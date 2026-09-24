<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const RUNNING_STATUSES = ['trialing', 'active', 'past_due', 'paused'];

    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table): void {
            $table->string('running_guard', 16)->nullable()->after('status');
        });

        $conflicts = DB::table('subscriptions')
            ->select('workspace_id', DB::raw('COUNT(*) as total'))
            ->whereIn('status', self::RUNNING_STATUSES)
            ->whereNull('deleted_at')
            ->groupBy('workspace_id')
            ->having('total', '>', 1)
            ->pluck('workspace_id')
            ->all();

        if ($conflicts !== []) {
            throw new RuntimeException(
                'Cannot add subscription running_guard: workspaces with multiple running subscriptions exist (ids: '
                .implode(', ', $conflicts).'). Resolve duplicates before migrating.'
            );
        }

        DB::table('subscriptions')
            ->whereIn('status', self::RUNNING_STATUSES)
            ->whereNull('deleted_at')
            ->update(['running_guard' => 'running']);

        Schema::table('subscriptions', function (Blueprint $table): void {
            $table->unique(['workspace_id', 'running_guard'], 'subscriptions_workspace_running_guard_unique');
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table): void {
            $table->dropUnique('subscriptions_workspace_running_guard_unique');
            $table->dropColumn('running_guard');
        });
    }
};
