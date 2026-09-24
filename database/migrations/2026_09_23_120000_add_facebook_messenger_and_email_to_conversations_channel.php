<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Align conversations.channel with inbox API validation (facebook_messenger, email).
     */
    public function up(): void
    {
        if (! Schema::hasTable('conversations')) {
            return;
        }

        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'mysql') {
            DB::statement("ALTER TABLE conversations MODIFY channel ENUM('whatsapp', 'web', 'manual', 'instagram', 'facebook_messenger', 'email') NOT NULL DEFAULT 'manual'");

            return;
        }

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
            DB::table('conversations')->where('channel', 'facebook_messenger')->update(['channel' => 'manual']);
            DB::table('conversations')->where('channel', 'email')->update(['channel' => 'manual']);
            DB::statement("ALTER TABLE conversations MODIFY channel ENUM('whatsapp', 'web', 'manual', 'instagram') NOT NULL DEFAULT 'manual'");
        }
    }
};
