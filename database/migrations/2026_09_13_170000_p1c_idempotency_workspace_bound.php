<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('api_idempotency_keys', function (Blueprint $table): void {
            if (! Schema::hasColumn('api_idempotency_keys', 'request_hash')) {
                $table->string('request_hash', 64)->nullable()->after('route');
            }
        });

        Schema::table('api_idempotency_keys', function (Blueprint $table): void {
            $table->dropForeign(['user_id']);
            $table->dropForeign(['workspace_id']);
        });

        Schema::table('api_idempotency_keys', function (Blueprint $table): void {
            $table->dropUnique(['user_id', 'key', 'route']);
        });

        Schema::table('api_idempotency_keys', function (Blueprint $table): void {
            $table->unique(
                ['user_id', 'workspace_id', 'key', 'route'],
                'api_idempotency_user_ws_key_route_unique'
            );
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('workspace_id')->references('id')->on('workspaces')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('api_idempotency_keys', function (Blueprint $table): void {
            $table->dropForeign(['user_id']);
            $table->dropForeign(['workspace_id']);
            $table->dropUnique('api_idempotency_user_ws_key_route_unique');
        });

        Schema::table('api_idempotency_keys', function (Blueprint $table): void {
            $table->unique(['user_id', 'key', 'route']);
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('workspace_id')->references('id')->on('workspaces')->nullOnDelete();
        });

        Schema::table('api_idempotency_keys', function (Blueprint $table): void {
            if (Schema::hasColumn('api_idempotency_keys', 'request_hash')) {
                $table->dropColumn('request_hash');
            }
        });
    }
};
