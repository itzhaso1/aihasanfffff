<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasTable('worker_engagements')
            && ! Schema::hasColumn('worker_engagements', 'job_description')) {
            Schema::table('worker_engagements', function (Blueprint $table): void {
                $table->text('job_description')->nullable()->after('job_title');
            });
        }

        if (Schema::hasTable('chat_catalog_items')) {
            Schema::table('chat_catalog_items', function (Blueprint $table): void {
                if (! Schema::hasColumn('chat_catalog_items', 'worker_min_price')) {
                    $table->decimal('worker_min_price', 12, 2)->nullable()->after('price');
                }
                if (! Schema::hasColumn('chat_catalog_items', 'worker_max_price')) {
                    $table->decimal('worker_max_price', 12, 2)->nullable()->after('worker_min_price');
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('worker_engagements')
            && Schema::hasColumn('worker_engagements', 'job_description')) {
            Schema::table('worker_engagements', function (Blueprint $table): void {
                $table->dropColumn('job_description');
            });
        }

        if (Schema::hasTable('chat_catalog_items')) {
            Schema::table('chat_catalog_items', function (Blueprint $table): void {
                if (Schema::hasColumn('chat_catalog_items', 'worker_max_price')) {
                    $table->dropColumn('worker_max_price');
                }
                if (Schema::hasColumn('chat_catalog_items', 'worker_min_price')) {
                    $table->dropColumn('worker_min_price');
                }
            });
        }
    }
};
