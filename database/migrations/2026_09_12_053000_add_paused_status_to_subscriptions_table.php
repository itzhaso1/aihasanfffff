<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('subscriptions')) {
            return;
        }

        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'mysql') {
            DB::statement("ALTER TABLE subscriptions MODIFY status ENUM('trialing', 'active', 'past_due', 'paused', 'cancelled', 'expired') NOT NULL DEFAULT 'trialing'");

            return;
        }

        // SQLite stores Laravel enums as CHECK constraints. Widen to string so paused is legal.
        Schema::table('subscriptions', function (Blueprint $table): void {
            $table->string('status', 32)->default('trialing')->change();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('subscriptions')) {
            return;
        }

        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::table('subscriptions')->where('status', 'paused')->update(['status' => 'cancelled']);
            DB::statement("ALTER TABLE subscriptions MODIFY status ENUM('trialing', 'active', 'past_due', 'cancelled', 'expired') NOT NULL DEFAULT 'trialing'");
        }
    }
};
