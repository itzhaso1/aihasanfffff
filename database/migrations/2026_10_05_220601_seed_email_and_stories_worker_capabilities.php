<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        DB::table('workforce_capability_definitions')->upsert(
            [
                [
                    'capability_key' => 'email.view',
                    'display_name' => 'View workspace email',
                    'description' => 'View workspace email messages and related read-only email data.',
                    'is_active' => true,
                    'sort_order' => 110,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                [
                    'capability_key' => 'stories.view',
                    'display_name' => 'View workspace updates',
                    'description' => 'View workspace stories and updates without publishing or deleting them.',
                    'is_active' => true,
                    'sort_order' => 120,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            ],
            ['capability_key'],
            ['display_name', 'description', 'is_active', 'sort_order', 'updated_at'],
        );
    }

    public function down(): void
    {
        if (! Schema::hasTable('worker_engagement_permissions')) {
            DB::table('workforce_capability_definitions')
                ->whereIn('capability_key', ['email.view', 'stories.view'])
                ->delete();

            return;
        }

        DB::table('workforce_capability_definitions')
            ->whereIn('capability_key', ['email.view', 'stories.view'])
            ->whereNotIn(
                'capability_key',
                DB::table('worker_engagement_permissions')->select('capability_key'),
            )
            ->delete();
    }
};
