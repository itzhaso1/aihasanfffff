<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('customers', 'client_reference')) {
            Schema::table('customers', function (Blueprint $table): void {
                $table->string('client_reference', 120)->nullable()->after('phone');
                $table->unique(['workspace_id', 'client_reference'], 'customers_workspace_client_reference_unique');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('customers', 'client_reference')) {
            Schema::table('customers', function (Blueprint $table): void {
                $table->dropUnique('customers_workspace_client_reference_unique');
                $table->dropColumn('client_reference');
            });
        }
    }
};
