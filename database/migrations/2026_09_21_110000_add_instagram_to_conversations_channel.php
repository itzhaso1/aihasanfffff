<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add Instagram as a first-class conversation channel without rewriting existing rows.
     */
    public function up(): void
    {
        if (! Schema::hasTable('conversations')) {
            return;
        }

        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'mysql') {
            DB::statement("ALTER TABLE conversations MODIFY channel ENUM('whatsapp', 'web', 'manual', 'instagram') NOT NULL DEFAULT 'manual'");

            return;
        }

        // SQLite stores Laravel enums as CHECK constraints. Widen to string so instagram is legal.
        Schema::table('conversations', function (Blueprint $table): void {
            $table->string('channel', 32)->default('manual')->change();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('conversations')) {
            return;
        }

        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::table('conversations')->where('channel', 'instagram')->update(['channel' => 'manual']);
            DB::statement("ALTER TABLE conversations MODIFY channel ENUM('whatsapp', 'web', 'manual') NOT NULL DEFAULT 'manual'");
        }
    }
};
